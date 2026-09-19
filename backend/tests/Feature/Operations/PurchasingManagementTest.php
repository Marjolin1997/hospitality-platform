<?php

use App\Models\Business;
use App\Models\Location;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

function pcmBusiness(string $name): Business
{
    return Business::query()->create([
        'name' => $name,
        'currency' => 'EUR',
        'timezone' => 'Europe/Berlin',
        'status' => 'active',
    ]);
}

function pcmLocation(Business $business, string $name = 'Main'): Location
{
    return Location::query()->create([
        'business_id' => $business->getKey(),
        'name' => $name,
        'code' => Str::upper(Str::substr(Str::slug($name, ''), 0, 10)).Str::upper(Str::random(3)),
        'type' => 'bar_cafe',
        'is_active' => true,
    ]);
}

function pcmUser(Business $business, array $permissions): User
{
    $user = User::query()->create([
        'name' => 'Purchasing User',
        'email' => Str::lower(Str::random(12)).'@example.test',
        'password' => 'Purchasing#Password123',
    ]);

    $role = Role::query()->create([
        'business_id' => $business->getKey(),
        'name' => 'Purchasing Role '.Str::random(5),
        'slug' => 'purchasing-'.Str::lower(Str::random(8)),
        'is_system' => false,
    ]);

    $role->permissions()->sync(
        Permission::query()->whereIn('key', $permissions)->pluck('id')
    );

    $user->businesses()->attach($business->getKey(), [
        'role_id' => $role->getKey(),
        'status' => 'active',
    ]);

    return $user;
}

function pcmHeaders(User $user, Business $business): array
{
    Sanctum::actingAs($user);

    return ['X-Business-Id' => $business->getKey()];
}

function pcmProduct(Business $business, string $name, bool $tracksStock = true): string
{
    $id = (string) Str::ulid();

    DB::table('products')->insert([
        'id' => $id,
        'business_id' => $business->getKey(),
        'name' => $name,
        'sku' => Str::upper(Str::random(6)),
        'sale_price' => '5.0000',
        'tax_rate' => '20.0000',
        'unit_code' => 'C62',
        'unit_label' => 'pcs',
        'tracks_stock' => $tracksStock,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function pcmSupplier(object $case, array $headers, string $name = 'Supply Co'): string
{
    return $case->postJson('/api/v1/purchasing/suppliers', [
        'name' => $name,
        'tax_number' => null,
        'contact_name' => 'Buyer Contact',
        'email' => 'supplier-'.Str::lower(Str::random(6)).'@example.test',
        'phone' => '+49 123',
        'address' => 'Supplier Street',
    ], $headers)->assertCreated()->json('data.id');
}

function pcmOrder(
    object $case,
    array $headers,
    string $locationId,
    string $supplierId,
    array $items,
): string {
    return $case->postJson('/api/v1/purchase-orders', [
        'location_id' => $locationId,
        'supplier_id' => $supplierId,
        'notes' => 'Weekly replenishment',
        'items' => $items,
    ], $headers)->assertCreated()->json('data.id');
}

test('supplier lifecycle is tenant scoped unique and cannot disable with open purchase orders', function (): void {
    $business = pcmBusiness('Supplier Lifecycle');
    $location = pcmLocation($business);
    $manager = pcmUser($business, ['purchasing.view', 'purchasing.manage']);
    $headers = pcmHeaders($manager, $business);

    $supplier = pcmSupplier($this, $headers, 'Coffee Supply');

    $this->postJson('/api/v1/purchasing/suppliers', [
        'name' => 'coffee supply',
        'tax_number' => null,
    ], $headers)->assertStatus(422)->assertJsonValidationErrors('name');

    $product = pcmProduct($business, 'Coffee Beans');
    $po = pcmOrder($this, $headers, $location->id, $supplier, [[
        'product_id' => $product,
        'quantity_ordered' => '10',
        'unit_cost' => '2.50',
    ]]);

    $this->patchJson("/api/v1/purchasing/suppliers/{$supplier}/status", [
        'is_active' => false,
    ], $headers)->assertStatus(422)->assertJsonValidationErrors('supplier');

    $this->postJson("/api/v1/purchase-orders/{$po}/cancel", [
        'reason' => 'Supplier unavailable',
    ], $headers)->assertOk()->assertJsonPath('data.status', 'cancelled');

    $this->patchJson("/api/v1/purchasing/suppliers/{$supplier}/status", [
        'is_active' => false,
    ], $headers)->assertOk()->assertJsonPath('data.is_active', false);

    $other = pcmBusiness('Supplier Other Tenant');
    $otherUser = pcmUser($other, ['purchasing.view', 'purchasing.manage']);

    $this->patchJson("/api/v1/purchasing/suppliers/{$supplier}/status", [
        'is_active' => true,
    ], pcmHeaders($otherUser, $other))->assertNotFound();
});

test('purchase order placement snapshots cost and partial receipts update inventory exactly once', function (): void {
    $business = pcmBusiness('PO Receiving');
    $location = pcmLocation($business);
    $manager = pcmUser($business, ['purchasing.view', 'purchasing.manage', 'inventory.receive']);
    $headers = pcmHeaders($manager, $business);

    $supplier = pcmSupplier($this, $headers);
    $coffee = pcmProduct($business, 'Coffee Beans');
    $milk = pcmProduct($business, 'Milk');

    $po = pcmOrder($this, $headers, $location->id, $supplier, [
        ['product_id' => $coffee, 'quantity_ordered' => '10', 'unit_cost' => '2.5000'],
        ['product_id' => $milk, 'quantity_ordered' => '20', 'unit_cost' => '1.2500'],
    ]);

    $detail = $this->getJson("/api/v1/purchase-orders/{$po}", $headers)
        ->assertOk()
        ->assertJsonPath('data.order.status', 'draft')
        ->assertJsonPath('data.order.total_cost', '50.0000')
        ->json('data');

    expect($detail['items'])->toHaveCount(2);

    $this->postJson("/api/v1/purchase-orders/{$po}/place", [], $headers)
        ->assertOk()
        ->assertJsonPath('data.status', 'ordered');

    $coffeeLine = collect($detail['items'])->firstWhere('product_id', $coffee);
    $milkLine = collect($detail['items'])->firstWhere('product_id', $milk);

    $receipt1 = $this->postJson("/api/v1/purchase-orders/{$po}/receipts", [
        'note' => 'First delivery',
        'items' => [
            ['purchase_order_item_id' => $coffeeLine->id ?? $coffeeLine['id'], 'quantity_received' => '4'],
            ['purchase_order_item_id' => $milkLine->id ?? $milkLine['id'], 'quantity_received' => '20'],
        ],
    ], $headers)->assertCreated();

    $receipt1Id = $receipt1->json('data.id');

    expect(DB::table('purchase_orders')->where('id', $po)->value('status'))->toBe('partially_received')
        ->and((string) DB::table('inventory_stocks')->where('location_id', $location->id)->where('product_id', $coffee)->value('quantity_on_hand'))->toBe('4.0000')
        ->and((string) DB::table('inventory_stocks')->where('location_id', $location->id)->where('product_id', $milk)->value('quantity_on_hand'))->toBe('20.0000')
        ->and(DB::table('inventory_movements')->where('reference_type', 'goods_receipt')->where('reference_id', $receipt1Id)->count())->toBe(2);

    $this->postJson("/api/v1/purchase-orders/{$po}/receipts", [
        'note' => 'Final delivery',
        'items' => [
            ['purchase_order_item_id' => $coffeeLine->id ?? $coffeeLine['id'], 'quantity_received' => '6'],
        ],
    ], $headers)->assertCreated();

    expect(DB::table('purchase_orders')->where('id', $po)->value('status'))->toBe('received')
        ->and((string) DB::table('inventory_stocks')->where('location_id', $location->id)->where('product_id', $coffee)->value('quantity_on_hand'))->toBe('10.0000')
        ->and((string) DB::table('purchase_order_items')->where('id', $coffeeLine->id ?? $coffeeLine['id'])->value('quantity_received'))->toBe('10.0000')
        ->and(DB::table('goods_receipts')->where('purchase_order_id', $po)->count())->toBe(2);

    $events = $this->getJson("/api/v1/purchase-orders/{$po}/events", $headers)
        ->assertOk()
        ->json('data');

    expect(collect($events)->pluck('event')->all())
        ->toContain('created', 'placed', 'goods_received');
});

test('over receipt rolls back receipt stock line progress and inventory movement atomically', function (): void {
    $business = pcmBusiness('PO Over Receipt');
    $location = pcmLocation($business);
    $manager = pcmUser($business, ['purchasing.view', 'purchasing.manage', 'inventory.receive']);
    $headers = pcmHeaders($manager, $business);
    $supplier = pcmSupplier($this, $headers);
    $product = pcmProduct($business, 'Syrup');

    $po = pcmOrder($this, $headers, $location->id, $supplier, [[
        'product_id' => $product,
        'quantity_ordered' => '5',
        'unit_cost' => '3',
    ]]);

    $this->postJson("/api/v1/purchase-orders/{$po}/place", [], $headers)->assertOk();

    $line = DB::table('purchase_order_items')->where('purchase_order_id', $po)->firstOrFail();

    $this->postJson("/api/v1/purchase-orders/{$po}/receipts", [
        'items' => [[
            'purchase_order_item_id' => $line->id,
            'quantity_received' => '6',
        ]],
    ], $headers)->assertStatus(422)->assertJsonValidationErrors('items');

    expect(DB::table('goods_receipts')->where('purchase_order_id', $po)->count())->toBe(0)
        ->and(DB::table('goods_receipt_items')->where('purchase_order_item_id', $line->id)->count())->toBe(0)
        ->and(DB::table('inventory_movements')->where('reference_type', 'goods_receipt')->count())->toBe(0)
        ->and(DB::table('inventory_stocks')->where('product_id', $product)->count())->toBe(0)
        ->and((string) DB::table('purchase_order_items')->where('id', $line->id)->value('quantity_received'))->toBe('0.0000');
});

test('purchase order cancellation is blocked after any goods receipt', function (): void {
    $business = pcmBusiness('PO Cancel Guard');
    $location = pcmLocation($business);
    $manager = pcmUser($business, ['purchasing.view', 'purchasing.manage', 'inventory.receive']);
    $headers = pcmHeaders($manager, $business);
    $supplier = pcmSupplier($this, $headers);
    $product = pcmProduct($business, 'Cups');

    $po = pcmOrder($this, $headers, $location->id, $supplier, [[
        'product_id' => $product,
        'quantity_ordered' => '10',
        'unit_cost' => '0.20',
    ]]);

    $this->postJson("/api/v1/purchase-orders/{$po}/place", [], $headers)->assertOk();
    $line = DB::table('purchase_order_items')->where('purchase_order_id', $po)->firstOrFail();

    $this->postJson("/api/v1/purchase-orders/{$po}/receipts", [
        'items' => [[
            'purchase_order_item_id' => $line->id,
            'quantity_received' => '1',
        ]],
    ], $headers)->assertCreated();

    $this->postJson("/api/v1/purchase-orders/{$po}/cancel", [
        'reason' => 'Try to cancel after receipt',
    ], $headers)->assertStatus(422)->assertJsonValidationErrors('purchase_order');
});

test('purchasing permissions separate order management receiving and view access', function (): void {
    $business = pcmBusiness('PO RBAC');
    $location = pcmLocation($business);
    $viewer = pcmUser($business, ['purchasing.view']);
    $manager = pcmUser($business, ['purchasing.view', 'purchasing.manage']);
    $receiver = pcmUser($business, ['purchasing.view', 'inventory.receive']);

    $viewerHeaders = pcmHeaders($viewer, $business);
    $this->getJson('/api/v1/purchasing/suppliers', $viewerHeaders)->assertOk();
    $this->getJson('/api/v1/purchase-orders?location_id='.$location->id, $viewerHeaders)->assertOk();
    $this->postJson('/api/v1/purchasing/suppliers', ['name' => 'Blocked'], $viewerHeaders)->assertForbidden();

    $managerHeaders = pcmHeaders($manager, $business);
    $supplier = pcmSupplier($this, $managerHeaders);
    $product = pcmProduct($business, 'RBAC Product');
    $po = pcmOrder($this, $managerHeaders, $location->id, $supplier, [[
        'product_id' => $product,
        'quantity_ordered' => '2',
        'unit_cost' => '1',
    ]]);
    $this->postJson("/api/v1/purchase-orders/{$po}/place", [], $managerHeaders)->assertOk();

    $line = DB::table('purchase_order_items')->where('purchase_order_id', $po)->firstOrFail();

    $this->postJson("/api/v1/purchase-orders/{$po}/receipts", [
        'items' => [['purchase_order_item_id' => $line->id, 'quantity_received' => '1']],
    ], $managerHeaders)->assertForbidden();

    $receiverHeaders = pcmHeaders($receiver, $business);
    $this->postJson("/api/v1/purchase-orders/{$po}/receipts", [
        'items' => [['purchase_order_item_id' => $line->id, 'quantity_received' => '1']],
    ], $receiverHeaders)->assertCreated();

    $this->postJson("/api/v1/purchase-orders/{$po}/cancel", [
        'reason' => 'Not allowed',
    ], $receiverHeaders)->assertForbidden();
});

test('purchase order routes cannot cross tenant boundaries', function (): void {
    $a = pcmBusiness('PO Tenant A');
    $b = pcmBusiness('PO Tenant B');
    $locationB = pcmLocation($b, 'B');
    $managerA = pcmUser($a, ['purchasing.view', 'purchasing.manage', 'inventory.receive']);
    $managerB = pcmUser($b, ['purchasing.view', 'purchasing.manage', 'inventory.receive']);

    $headersB = pcmHeaders($managerB, $b);
    $supplierB = pcmSupplier($this, $headersB);
    $productB = pcmProduct($b, 'Foreign Product');
    $poB = pcmOrder($this, $headersB, $locationB->id, $supplierB, [[
        'product_id' => $productB,
        'quantity_ordered' => '3',
        'unit_cost' => '2',
    ]]);

    $headersA = pcmHeaders($managerA, $a);

    $this->getJson("/api/v1/purchase-orders/{$poB}", $headersA)->assertNotFound();
    $this->getJson("/api/v1/purchase-orders/{$poB}/events", $headersA)->assertNotFound();
    $this->postJson("/api/v1/purchase-orders/{$poB}/place", [], $headersA)->assertNotFound();
    $this->postJson("/api/v1/purchase-orders/{$poB}/cancel", ['reason' => 'Foreign'], $headersA)->assertNotFound();
});

test('location disable and stock tracking changes respect open purchasing dependencies', function (): void {
    $business = pcmBusiness('PO Cross Domain');
    $location = pcmLocation($business);
    $secondLocation = pcmLocation($business, 'Second');
    $manager = pcmUser($business, [
        'purchasing.view',
        'purchasing.manage',
        'inventory.receive',
        'business.settings.manage',
        'products.manage',
        'products.view',
    ]);
    $headers = pcmHeaders($manager, $business);
    $supplier = pcmSupplier($this, $headers);
    $product = pcmProduct($business, 'Tracked Item');

    $po = pcmOrder($this, $headers, $location->id, $supplier, [[
        'product_id' => $product,
        'quantity_ordered' => '5',
        'unit_cost' => '4',
    ]]);

    $this->patchJson("/api/v1/management/locations/{$location->id}/status", [
        'is_active' => false,
    ], $headers)->assertStatus(422)->assertJsonValidationErrors('location');

    $row = DB::table('products')->where('id', $product)->firstOrFail();

    $this->postJson('/api/v1/management/products', [
        'id' => $product,
        'name' => $row->name,
        'category_id' => null,
        'sku' => $row->sku,
        'sale_price' => $row->sale_price,
        'tax_rate' => $row->tax_rate,
        'unit_code' => $row->unit_code,
        'unit_label' => $row->unit_label,
        'preparation_station' => null,
        'tracks_stock' => false,
        'is_active' => true,
    ], $headers)->assertStatus(422)->assertJsonValidationErrors('tracks_stock');

    $this->postJson("/api/v1/purchase-orders/{$po}/cancel", [
        'reason' => 'Cancel dependency',
    ], $headers)->assertOk();

    $this->postJson('/api/v1/management/products', [
        'id' => $product,
        'name' => $row->name,
        'category_id' => null,
        'sku' => $row->sku,
        'sale_price' => $row->sale_price,
        'tax_rate' => $row->tax_rate,
        'unit_code' => $row->unit_code,
        'unit_label' => $row->unit_label,
        'preparation_station' => null,
        'tracks_stock' => false,
        'is_active' => true,
    ], $headers)->assertOk()
        ->assertJsonPath('data.tracks_stock', false);

    expect($secondLocation->is_active)->toBeTrue();
});
