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

function icmBusiness(string $name): Business
{
    return Business::query()->create([
        'name' => $name,
        'currency' => 'EUR',
        'timezone' => 'Europe/Berlin',
        'status' => 'active',
    ]);
}

function icmLocation(Business $business, string $name): Location
{
    return Location::query()->create([
        'business_id' => $business->getKey(),
        'name' => $name,
        'code' => Str::upper(Str::substr(Str::slug($name, ''), 0, 10)).Str::upper(Str::random(3)),
        'type' => 'bar_cafe',
        'is_active' => true,
    ]);
}

function icmUser(Business $business, array $permissions): User
{
    $user = User::query()->create([
        'name' => 'Inventory User',
        'email' => Str::lower(Str::random(12)).'@example.test',
        'password' => 'Inventory#Password123',
    ]);

    $role = Role::query()->create([
        'business_id' => $business->getKey(),
        'name' => 'Inventory Role '.Str::random(5),
        'slug' => 'inventory-'.Str::lower(Str::random(8)),
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

function icmHeaders(User $user, Business $business): array
{
    Sanctum::actingAs($user);

    return ['X-Business-Id' => $business->getKey()];
}

function icmProduct(Business $business, string $name): string
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
        'tracks_stock' => true,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function icmStock(Business $business, Location $location, string $productId, string $quantity): void
{
    DB::table('inventory_stocks')->insert([
        'id' => (string) Str::ulid(),
        'business_id' => $business->getKey(),
        'location_id' => $location->getKey(),
        'product_id' => $productId,
        'quantity_on_hand' => $quantity,
        'reorder_level' => '0.0000',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('reorder thresholds are configuration changes with immutable audit history', function (): void {
    $business = icmBusiness('Reorder Audit');
    $location = icmLocation($business, 'Main');
    $user = icmUser($business, ['inventory.view', 'inventory.adjust']);
    $headers = icmHeaders($user, $business);
    $product = icmProduct($business, 'Tonic Water');

    $this->putJson("/api/v1/inventory/products/{$product}/reorder-level", [
        'location_id' => $location->id,
        'reorder_level' => '3.5000',
    ], $headers)->assertOk()
        ->assertJsonPath('data.product_id', $product)
        ->assertJsonPath('data.reorder_level', '3.5000');

    expect((string) DB::table('inventory_stocks')
        ->where('business_id', $business->id)
        ->where('location_id', $location->id)
        ->where('product_id', $product)
        ->value('quantity_on_hand'))->toBe('0.0000')
        ->and((string) DB::table('inventory_stocks')
        ->where('business_id', $business->id)
        ->where('location_id', $location->id)
        ->where('product_id', $product)
        ->value('reorder_level'))->toBe('3.5000')
        ->and(DB::table('inventory_movements')->where('product_id', $product)->count())->toBe(0);

    $this->putJson("/api/v1/inventory/products/{$product}/reorder-level", [
        'location_id' => $location->id,
        'reorder_level' => '5',
    ], $headers)->assertOk()
        ->assertJsonPath('data.reorder_level', '5.0000');

    // A no-op must not create duplicate audit noise.
    $this->putJson("/api/v1/inventory/products/{$product}/reorder-level", [
        'location_id' => $location->id,
        'reorder_level' => '5.0000',
    ], $headers)->assertOk();

    $audits = DB::table('business_configuration_audits')
        ->where('business_id', $business->id)
        ->where('location_id', $location->id)
        ->where('entity_type', 'inventory_stock')
        ->where('action', 'reorder_level_changed')
        ->get();

    expect($audits)->toHaveCount(2);

    $history = $this->getJson(
        "/api/v1/inventory/products/{$product}/reorder-level/events?location_id={$location->id}",
        $headers,
    )->assertOk()->json('data');

    expect($history)->toHaveCount(2)
        ->and($history[0]['new_state']['reorder_level'])->toBe('5.0000')
        ->and($history[0]['performed_by_name'])->toBe('Inventory User');

    $other = icmBusiness('Reorder Foreign');
    $otherLocation = icmLocation($other, 'Other');
    $otherUser = icmUser($other, ['inventory.view', 'inventory.adjust']);

    $this->getJson(
        "/api/v1/inventory/products/{$product}/reorder-level/events?location_id={$otherLocation->id}",
        icmHeaders($otherUser, $other),
    )->assertNotFound();
});

test('stock tracking cannot be disabled while physical stock or a draft count still exists', function (): void {
    $business = icmBusiness('Tracking Safety');
    $location = icmLocation($business, 'Main');
    $user = icmUser($business, ['inventory.view', 'inventory.adjust', 'products.view', 'products.manage']);
    $headers = icmHeaders($user, $business);
    $product = icmProduct($business, 'Tracked Product');

    icmStock($business, $location, $product, '5.0000');
    $row = DB::table('products')->where('id', $product)->firstOrFail();

    $payload = [
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
    ];

    $this->postJson('/api/v1/management/products', $payload, $headers)
        ->assertStatus(422)
        ->assertJsonValidationErrors('tracks_stock');

    $this->postJson('/api/v1/inventory/adjustments', [
        'idempotency_key' => (string) Str::uuid(),
        'location_id' => $location->id,
        'product_id' => $product,
        'quantity_delta' => '-5',
        'note' => 'Verified zero balance before policy change',
    ], $headers)->assertOk();

    $countId = $this->postJson('/api/v1/inventory/counts', [
        'location_id' => $location->id,
    ], $headers)->assertCreated()->json('data.id');

    $this->postJson('/api/v1/management/products', $payload, $headers)
        ->assertStatus(422)
        ->assertJsonValidationErrors('tracks_stock');

    $this->postJson("/api/v1/inventory/counts/{$countId}/cancel", [
        'reason' => 'Close count before stock policy change',
    ], $headers)->assertOk();

    $this->postJson('/api/v1/management/products', $payload, $headers)
        ->assertOk()
        ->assertJsonPath('data.tracks_stock', false);

    expect(DB::table('inventory_stocks')
        ->where('business_id', $business->id)
        ->where('product_id', $product)
        ->where('quantity_on_hand', '!=', 0)
        ->exists())->toBeFalse();
});

test('stock transfer posts balanced immutable ledger movements across locations', function (): void {
    $business = icmBusiness('Transfer Balance');
    $source = icmLocation($business, 'Source');
    $destination = icmLocation($business, 'Destination');
    $user = icmUser($business, ['inventory.view', 'inventory.transfer']);
    $headers = icmHeaders($user, $business);
    $coffee = icmProduct($business, 'Coffee');
    $milk = icmProduct($business, 'Milk');

    icmStock($business, $source, $coffee, '10.0000');
    icmStock($business, $source, $milk, '8.0000');

    $response = $this->postJson('/api/v1/inventory/transfers', [
        'idempotency_key' => (string) Str::uuid(),
        'source_location_id' => $source->id,
        'destination_location_id' => $destination->id,
        'note' => 'Replenish second bar',
        'items' => [
            ['product_id' => $coffee, 'quantity' => '4'],
            ['product_id' => $milk, 'quantity' => '3.5'],
        ],
    ], $headers)->assertCreated()
        ->assertJsonPath('data.status', 'posted');

    $transferId = $response->json('data.id');

    expect((string) DB::table('inventory_stocks')->where('location_id', $source->id)->where('product_id', $coffee)->value('quantity_on_hand'))->toBe('6.0000')
        ->and((string) DB::table('inventory_stocks')->where('location_id', $destination->id)->where('product_id', $coffee)->value('quantity_on_hand'))->toBe('4.0000')
        ->and((string) DB::table('inventory_stocks')->where('location_id', $source->id)->where('product_id', $milk)->value('quantity_on_hand'))->toBe('4.5000')
        ->and((string) DB::table('inventory_stocks')->where('location_id', $destination->id)->where('product_id', $milk)->value('quantity_on_hand'))->toBe('3.5000')
        ->and(DB::table('inventory_transfer_items')->where('inventory_transfer_id', $transferId)->count())->toBe(2)
        ->and(DB::table('inventory_movements')->where('reference_type', 'inventory_transfer')->where('reference_id', $transferId)->where('type', 'transfer_out')->count())->toBe(2)
        ->and(DB::table('inventory_movements')->where('reference_type', 'inventory_transfer')->where('reference_id', $transferId)->where('type', 'transfer_in')->count())->toBe(2);

    $this->getJson('/api/v1/inventory/transfers?location_id='.$source->id, $headers)
        ->assertOk()
        ->assertJsonPath('data.0.id', $transferId)
        ->assertJsonPath('data.0.source_location_name', 'Source')
        ->assertJsonPath('data.0.destination_location_name', 'Destination');

    $this->getJson("/api/v1/inventory/transfers/{$transferId}", $headers)
        ->assertOk()
        ->assertJsonCount(2, 'data.items');

    $ledger = $this->getJson('/api/v1/inventory/movements?location_id='.$source->id, $headers)
        ->assertOk()
        ->json('data');

    expect(collect($ledger)->where('reference_id', $transferId)->pluck('type')->sort()->values()->all())
        ->toBe(['transfer_out', 'transfer_out']);
});

test('stock transfer idempotency prevents duplicate balance movement on retries', function (): void {
    $business = icmBusiness('Transfer Idempotency');
    $source = icmLocation($business, 'Source');
    $destination = icmLocation($business, 'Destination');
    $user = icmUser($business, ['inventory.view', 'inventory.transfer']);
    $headers = icmHeaders($user, $business);
    $product = icmProduct($business, 'Retry Product');

    icmStock($business, $source, $product, '10.0000');

    $key = (string) Str::uuid();
    $payload = [
        'idempotency_key' => $key,
        'source_location_id' => $source->id,
        'destination_location_id' => $destination->id,
        'note' => 'Retry-safe transfer',
        'items' => [['product_id' => $product, 'quantity' => '4']],
    ];

    $first = $this->postJson('/api/v1/inventory/transfers', $payload, $headers)
        ->assertCreated();

    $second = $this->postJson('/api/v1/inventory/transfers', $payload, $headers)
        ->assertCreated();

    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and(DB::table('inventory_transfers')->count())->toBe(1)
        ->and(DB::table('inventory_transfer_items')->count())->toBe(1)
        ->and(DB::table('inventory_movements')->where('reference_type', 'inventory_transfer')->count())->toBe(2)
        ->and((string) DB::table('inventory_stocks')->where('location_id', $source->id)->where('product_id', $product)->value('quantity_on_hand'))->toBe('6.0000')
        ->and((string) DB::table('inventory_stocks')->where('location_id', $destination->id)->where('product_id', $product)->value('quantity_on_hand'))->toBe('4.0000');

    $this->postJson('/api/v1/inventory/transfers', [
        ...$payload,
        'note' => 'Different transfer payload',
    ], $headers)->assertStatus(422)
        ->assertJsonValidationErrors('idempotency_key');

    expect(DB::table('inventory_transfers')->count())->toBe(1)
        ->and((string) DB::table('inventory_stocks')->where('location_id', $source->id)->where('product_id', $product)->value('quantity_on_hand'))->toBe('6.0000');
});

test('over transfer rolls back every stock and ledger mutation', function (): void {
    $business = icmBusiness('Transfer Rollback');
    $source = icmLocation($business, 'Source');
    $destination = icmLocation($business, 'Destination');
    $user = icmUser($business, ['inventory.view', 'inventory.transfer']);
    $headers = icmHeaders($user, $business);
    $product = icmProduct($business, 'Syrup');

    icmStock($business, $source, $product, '2.0000');

    $this->postJson('/api/v1/inventory/transfers', [
        'idempotency_key' => (string) Str::uuid(),
        'source_location_id' => $source->id,
        'destination_location_id' => $destination->id,
        'note' => 'Too much transfer',
        'items' => [['product_id' => $product, 'quantity' => '3']],
    ], $headers)->assertStatus(422)
        ->assertJsonValidationErrors('items');

    expect((string) DB::table('inventory_stocks')->where('location_id', $source->id)->where('product_id', $product)->value('quantity_on_hand'))->toBe('2.0000')
        ->and(DB::table('inventory_stocks')->where('location_id', $destination->id)->where('product_id', $product)->count())->toBe(0)
        ->and(DB::table('inventory_transfers')->count())->toBe(0)
        ->and(DB::table('inventory_movements')->count())->toBe(0);
});

test('stock count draft can be saved and posted into exact audited variances', function (): void {
    $business = icmBusiness('Count Lifecycle');
    $location = icmLocation($business, 'Main');
    $user = icmUser($business, ['inventory.view', 'inventory.adjust']);
    $headers = icmHeaders($user, $business);
    $coffee = icmProduct($business, 'Coffee');
    $milk = icmProduct($business, 'Milk');

    icmStock($business, $location, $coffee, '10.0000');
    icmStock($business, $location, $milk, '5.0000');

    $created = $this->postJson('/api/v1/inventory/counts', [
        'location_id' => $location->id,
        'note' => 'Month-end count',
    ], $headers)->assertCreated()
        ->assertJsonPath('data.status', 'draft');

    $countId = $created->json('data.id');

    $this->postJson('/api/v1/inventory/counts', [
        'location_id' => $location->id,
    ], $headers)->assertStatus(422)
        ->assertJsonValidationErrors('count');

    $detail = $this->getJson("/api/v1/inventory/counts/{$countId}", $headers)
        ->assertOk()
        ->assertJsonCount(2, 'data.items')
        ->json('data');

    $coffeeLine = collect($detail['items'])->firstWhere('product_id', $coffee);
    $milkLine = collect($detail['items'])->firstWhere('product_id', $milk);

    $this->putJson("/api/v1/inventory/counts/{$countId}", [
        'items' => [
            ['inventory_count_item_id' => $coffeeLine['id'], 'counted_quantity' => '9'],
            ['inventory_count_item_id' => $milkLine['id'], 'counted_quantity' => '7'],
        ],
    ], $headers)->assertOk()
        ->assertJsonPath('data.status', 'draft');

    $this->postJson("/api/v1/inventory/counts/{$countId}/post", [], $headers)
        ->assertOk()
        ->assertJsonPath('data.status', 'posted');

    expect((string) DB::table('inventory_stocks')->where('location_id', $location->id)->where('product_id', $coffee)->value('quantity_on_hand'))->toBe('9.0000')
        ->and((string) DB::table('inventory_stocks')->where('location_id', $location->id)->where('product_id', $milk)->value('quantity_on_hand'))->toBe('7.0000')
        ->and((string) DB::table('inventory_count_items')->where('id', $coffeeLine['id'])->value('variance_quantity'))->toBe('-1.0000')
        ->and((string) DB::table('inventory_count_items')->where('id', $milkLine['id'])->value('variance_quantity'))->toBe('2.0000')
        ->and(DB::table('inventory_movements')->where('reference_type', 'inventory_count')->where('reference_id', $countId)->where('type', 'stock_count')->count())->toBe(2);

    $events = $this->getJson("/api/v1/inventory/counts/{$countId}/events", $headers)
        ->assertOk()
        ->json('data');

    expect(collect($events)->pluck('event')->all())->toContain('created', 'draft_updated', 'posted');

    $this->putJson("/api/v1/inventory/counts/{$countId}", [
        'items' => [['inventory_count_item_id' => $coffeeLine['id'], 'counted_quantity' => '8']],
    ], $headers)->assertStatus(422)
        ->assertJsonValidationErrors('count');
});

test('stock count posting is blocked when stock changed after the snapshot', function (): void {
    $business = icmBusiness('Count Stale');
    $location = icmLocation($business, 'Main');
    $user = icmUser($business, ['inventory.view', 'inventory.adjust']);
    $headers = icmHeaders($user, $business);
    $product = icmProduct($business, 'Beans');

    icmStock($business, $location, $product, '5.0000');

    $countId = $this->postJson('/api/v1/inventory/counts', [
        'location_id' => $location->id,
    ], $headers)->assertCreated()->json('data.id');

    $line = DB::table('inventory_count_items')->where('inventory_count_id', $countId)->firstOrFail();

    $this->putJson("/api/v1/inventory/counts/{$countId}", [
        'items' => [['inventory_count_item_id' => $line->id, 'counted_quantity' => '5']],
    ], $headers)->assertOk();

    $this->postJson('/api/v1/inventory/adjustments', [
        'idempotency_key' => (string) Str::uuid(),
        'location_id' => $location->id,
        'product_id' => $product,
        'quantity_delta' => '1',
        'note' => 'Delivery correction',
    ], $headers)->assertOk();

    $this->postJson("/api/v1/inventory/counts/{$countId}/post", [], $headers)
        ->assertStatus(422)
        ->assertJsonValidationErrors('count');

    expect((string) DB::table('inventory_stocks')->where('location_id', $location->id)->where('product_id', $product)->value('quantity_on_hand'))->toBe('6.0000')
        ->and(DB::table('inventory_movements')->where('reference_type', 'inventory_count')->where('reference_id', $countId)->count())->toBe(0)
        ->and(DB::table('inventory_counts')->where('id', $countId)->value('status'))->toBe('draft');
});

test('draft count can be cancelled and blocks location disable until closed', function (): void {
    $business = icmBusiness('Count Location Guard');
    $location = icmLocation($business, 'Counted');
    icmLocation($business, 'Other');
    $user = icmUser($business, ['inventory.view', 'inventory.adjust', 'business.settings.manage']);
    $headers = icmHeaders($user, $business);
    icmProduct($business, 'Counted Product');

    $countId = $this->postJson('/api/v1/inventory/counts', [
        'location_id' => $location->id,
    ], $headers)->assertCreated()->json('data.id');

    $this->patchJson("/api/v1/management/locations/{$location->id}/status", [
        'is_active' => false,
    ], $headers)->assertStatus(422)
        ->assertJsonValidationErrors('location');

    $this->postJson("/api/v1/inventory/counts/{$countId}/cancel", [
        'reason' => 'Count restarted',
    ], $headers)->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    $this->patchJson("/api/v1/management/locations/{$location->id}/status", [
        'is_active' => false,
    ], $headers)->assertOk()
        ->assertJsonPath('data.is_active', false);
});

test('inventory control permissions and tenant boundaries are enforced', function (): void {
    $a = icmBusiness('Inventory Tenant A');
    $b = icmBusiness('Inventory Tenant B');
    $a1 = icmLocation($a, 'A1');
    $a2 = icmLocation($a, 'A2');
    $b1 = icmLocation($b, 'B1');
    $b2 = icmLocation($b, 'B2');

    $viewer = icmUser($a, ['inventory.view']);
    $transferUser = icmUser($a, ['inventory.view', 'inventory.transfer']);
    $adjustUser = icmUser($a, ['inventory.view', 'inventory.adjust']);
    $foreign = icmUser($b, ['inventory.view', 'inventory.transfer', 'inventory.adjust']);

    $productA = icmProduct($a, 'A Product');
    $productB = icmProduct($b, 'B Product');
    icmStock($a, $a1, $productA, '10.0000');
    icmStock($b, $b1, $productB, '10.0000');

    $viewerHeaders = icmHeaders($viewer, $a);
    $this->getJson('/api/v1/inventory/movements?location_id='.$a1->id, $viewerHeaders)->assertOk();
    $this->postJson('/api/v1/inventory/transfers', [
        'idempotency_key' => (string) Str::uuid(),
        'source_location_id' => $a1->id,
        'destination_location_id' => $a2->id,
        'note' => 'Blocked transfer',
        'items' => [['product_id' => $productA, 'quantity' => '1']],
    ], $viewerHeaders)->assertForbidden();

    $transferHeaders = icmHeaders($transferUser, $a);
    $this->postJson('/api/v1/inventory/counts', [
        'location_id' => $a1->id,
    ], $transferHeaders)->assertForbidden();

    $adjustHeaders = icmHeaders($adjustUser, $a);
    $countId = $this->postJson('/api/v1/inventory/counts', [
        'location_id' => $a1->id,
    ], $adjustHeaders)->assertCreated()->json('data.id');

    $foreignHeaders = icmHeaders($foreign, $b);
    $this->getJson("/api/v1/inventory/counts/{$countId}", $foreignHeaders)->assertNotFound();
    $this->getJson("/api/v1/inventory/counts/{$countId}/events", $foreignHeaders)->assertNotFound();

    $this->postJson('/api/v1/inventory/transfers', [
        'idempotency_key' => (string) Str::uuid(),
        'source_location_id' => $b1->id,
        'destination_location_id' => $a2->id,
        'note' => 'Cross tenant destination',
        'items' => [['product_id' => $productB, 'quantity' => '1']],
    ], $foreignHeaders)->assertStatus(422);

    expect((string) DB::table('inventory_stocks')->where('location_id', $b1->id)->where('product_id', $productB)->value('quantity_on_hand'))->toBe('10.0000')
        ->and(DB::table('inventory_stocks')->where('location_id', $b2->id)->where('product_id', $productB)->count())->toBe(0);
});
