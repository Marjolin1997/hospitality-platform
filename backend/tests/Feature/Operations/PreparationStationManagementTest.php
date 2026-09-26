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

function psmBusiness(string $name): Business
{
    return Business::query()->create([
        'name' => $name,
        'currency' => 'EUR',
        'timezone' => 'Europe/Berlin',
        'status' => 'active',
    ]);
}

function psmLocation(Business $business): Location
{
    return Location::query()->create([
        'business_id' => $business->getKey(),
        'name' => 'Main',
        'code' => 'MAIN'.Str::upper(Str::random(4)),
        'type' => 'bar_cafe',
        'is_active' => true,
    ]);
}

function psmUser(Business $business, array $permissions): User
{
    $user = User::query()->create([
        'name' => 'Station User',
        'email' => Str::lower(Str::random(12)).'@example.test',
        'password' => 'Station#Password123',
    ]);

    $role = Role::query()->create([
        'business_id' => $business->getKey(),
        'name' => 'Station Role '.Str::random(5),
        'slug' => 'station-role-'.Str::lower(Str::random(8)),
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

function psmHeaders(User $user, Business $business): array
{
    Sanctum::actingAs($user);

    return ['X-Business-Id' => $business->getKey()];
}

test('station code rename migrates current products but preserves historical order routing snapshots', function (): void {
    $business = psmBusiness('Station Rename');
    $location = psmLocation($business);
    $user = psmUser($business, ['stations.view', 'stations.manage', 'products.view', 'products.manage']);
    $headers = psmHeaders($user, $business);

    $station = $this->postJson('/api/v1/preparation-stations', [
        'name' => 'Main Bar',
        'code' => 'bar',
        'sort_order' => 10,
    ], $headers)->assertCreated()
        ->assertJsonPath('data.name', 'Main Bar')
        ->assertJsonPath('data.code', 'bar')
        ->json('data.id');

    $product = $this->postJson('/api/v1/management/products', [
        'name' => 'Espresso',
        'category_id' => null,
        'sku' => 'ESP-1',
        'sale_price' => '2.5000',
        'tax_rate' => '20',
        'unit_code' => 'C62',
        'unit_label' => 'pcs',
        'preparation_station' => 'bar',
        'tracks_stock' => false,
        'is_active' => true,
    ], $headers)->assertCreated()->json('data.id');

    $orderId = (string) Str::ulid();
    DB::table('orders')->insert([
        'id' => $orderId,
        'business_id' => $business->id,
        'location_id' => $location->id,
        'venue_table_id' => null,
        'opened_by_user_id' => $user->id,
        'number' => 'STATION-SNAPSHOT-1',
        'type' => 'counter',
        'status' => 'open',
        'currency' => 'EUR',
        'subtotal' => '2.5000',
        'discount_total' => '0.0000',
        'tax_total' => '0.5000',
        'grand_total' => '3.0000',
        'opened_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $orderItemId = (string) Str::ulid();
    DB::table('order_items')->insert([
        'id' => $orderItemId,
        'business_id' => $business->id,
        'order_id' => $orderId,
        'product_id' => $product,
        'product_name_snapshot' => 'Espresso',
        'sku_snapshot' => 'ESP-1',
        'quantity' => '1.0000',
        'unit_price' => '2.5000',
        'tax_rate' => '20.0000',
        'line_subtotal' => '2.5000',
        'line_tax' => '0.5000',
        'line_total' => '3.0000',
        'preparation_station' => 'bar',
        'preparation_status' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->postJson('/api/v1/preparation-stations', [
        'id' => $station,
        'name' => 'Cocktail & Coffee Bar',
        'code' => 'main-bar',
        'sort_order' => 5,
    ], $headers)->assertOk()
        ->assertJsonPath('data.code', 'main-bar');

    expect(DB::table('products')->where('id', $product)->value('preparation_station'))->toBe('main-bar')
        ->and(DB::table('order_items')->where('id', $orderItemId)->value('preparation_station'))->toBe('bar');

    $history = $this->getJson("/api/v1/preparation-stations/{$station}/events", $headers)
        ->assertOk()
        ->json('data');

    expect(collect($history)->pluck('action')->all())->toContain('created', 'updated')
        ->and($history[0]['new_state']['code'])->toBe('main-bar');
});

test('station disable and product reactivation respect routing dependencies', function (): void {
    $business = psmBusiness('Station Disable Guard');
    $user = psmUser($business, ['stations.view', 'stations.manage', 'products.view', 'products.manage']);
    $headers = psmHeaders($user, $business);

    $station = $this->postJson('/api/v1/preparation-stations', [
        'name' => 'Kitchen',
        'code' => 'kitchen',
        'sort_order' => 20,
    ], $headers)->assertCreated()->json('data.id');

    $product = $this->postJson('/api/v1/management/products', [
        'name' => 'Toast',
        'category_id' => null,
        'sku' => 'TOAST-1',
        'sale_price' => '4.0000',
        'tax_rate' => '20',
        'unit_code' => 'C62',
        'unit_label' => 'pcs',
        'preparation_station' => 'kitchen',
        'tracks_stock' => false,
        'is_active' => true,
    ], $headers)->assertCreated()->json('data.id');

    $this->patchJson("/api/v1/preparation-stations/{$station}/status", [
        'is_active' => false,
    ], $headers)->assertStatus(422)
        ->assertJsonValidationErrors('station');

    $this->patchJson("/api/v1/management/products/{$product}/status", [
        'is_active' => false,
    ], $headers)->assertOk();

    $this->patchJson("/api/v1/preparation-stations/{$station}/status", [
        'is_active' => false,
    ], $headers)->assertOk()
        ->assertJsonPath('data.is_active', false);

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
        'preparation_station' => 'kitchen',
        'tracks_stock' => false,
        'is_active' => false,
    ], $headers)->assertOk();

    $this->patchJson("/api/v1/management/products/{$product}/status", [
        'is_active' => true,
    ], $headers)->assertStatus(422)
        ->assertJsonValidationErrors('product');

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
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.preparation_station', null);
});

test('station identities permissions and tenant history are isolated', function (): void {
    $a = psmBusiness('Station Tenant A');
    $b = psmBusiness('Station Tenant B');

    $managerA = psmUser($a, ['stations.view', 'stations.manage']);
    $viewerA = psmUser($a, ['stations.view']);
    $managerB = psmUser($b, ['stations.view', 'stations.manage']);

    $headersA = psmHeaders($managerA, $a);

    $station = $this->postJson('/api/v1/preparation-stations', [
        'name' => 'Dessert',
        'code' => 'dessert',
        'sort_order' => 30,
    ], $headersA)->assertCreated()->json('data.id');

    $this->postJson('/api/v1/preparation-stations', [
        'name' => 'dessert',
        'code' => 'dessert-two',
        'sort_order' => 40,
    ], $headersA)->assertStatus(422)
        ->assertJsonValidationErrors('name');

    $this->postJson('/api/v1/preparation-stations', [
        'name' => 'Another',
        'code' => 'DESSERT',
        'sort_order' => 50,
    ], $headersA)->assertStatus(422)
        ->assertJsonValidationErrors('code');

    $viewerHeaders = psmHeaders($viewerA, $a);
    $this->getJson('/api/v1/preparation-stations', $viewerHeaders)->assertOk();
    $this->postJson('/api/v1/preparation-stations', [
        'name' => 'Blocked',
        'code' => 'blocked',
        'sort_order' => 1,
    ], $viewerHeaders)->assertForbidden();

    $foreignHeaders = psmHeaders($managerB, $b);
    $this->getJson("/api/v1/preparation-stations/{$station}/events", $foreignHeaders)->assertNotFound();
    $this->patchJson("/api/v1/preparation-stations/{$station}/status", [
        'is_active' => false,
    ], $foreignHeaders)->assertNotFound();
});
