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

function inventorySafetyContext(): array
{
    $business = Business::query()->create([
        'name' => 'Inventory Safety',
        'currency' => 'EUR',
        'timezone' => 'Europe/Berlin',
        'status' => 'active',
    ]);
    $location = Location::query()->create([
        'business_id' => $business->id,
        'name' => 'Main',
        'code' => 'MAIN',
        'type' => 'bar',
        'is_active' => true,
    ]);
    $user = User::query()->create([
        'name' => 'Inventory Manager',
        'email' => Str::lower(Str::random(10)).'@example.test',
        'password' => bcrypt('password'),
    ]);
    $role = Role::query()->create([
        'business_id' => $business->id,
        'name' => 'Inventory',
        'slug' => 'inventory-'.Str::lower(Str::random(6)),
        'is_system' => false,
    ]);
    $role->permissions()->sync(Permission::query()->whereIn('key', ['inventory.view', 'inventory.adjust'])->pluck('id'));
    $user->businesses()->attach($business->id, ['role_id' => $role->id, 'status' => 'active']);
    $product = (string) Str::ulid();
    DB::table('products')->insert([
        'id' => $product,
        'business_id' => $business->id,
        'name' => 'Tracked Bottle',
        'sale_price' => '5.0000',
        'tax_rate' => '0.0000',
        'is_active' => true,
        'tracks_stock' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    Sanctum::actingAs($user);
    return [$business, $location, $product, ['X-Business-Id' => $business->id]];
}

test('manual inventory adjustment cannot make stock negative', function (): void {
    [$business, $location, $product, $headers] = inventorySafetyContext();

    $this->postJson('/api/v1/inventory/adjustments', [
        'location_id' => $location->id,
        'product_id' => $product,
        'quantity_delta' => '5.0000',
        'note' => 'Opening count',
    ], $headers)->assertOk();

    $this->postJson('/api/v1/inventory/adjustments', [
        'location_id' => $location->id,
        'product_id' => $product,
        'quantity_delta' => '-6.0000',
        'note' => 'Incorrect removal',
    ], $headers)->assertStatus(422)->assertJsonValidationErrors('quantity_delta');

    expect((string) DB::table('inventory_stocks')
        ->where('business_id', $business->id)
        ->where('location_id', $location->id)
        ->where('product_id', $product)
        ->value('quantity_on_hand'))->toBe('5.0000');

    expect(DB::table('inventory_movements')
        ->where('business_id', $business->id)
        ->where('location_id', $location->id)
        ->where('product_id', $product)
        ->count())->toBe(1);
});

test('failed negative opening adjustment creates neither stock nor ledger movement', function (): void {
    [$business, $location, $product, $headers] = inventorySafetyContext();

    $this->postJson('/api/v1/inventory/adjustments', [
        'location_id' => $location->id,
        'product_id' => $product,
        'quantity_delta' => '-1.0000',
        'note' => 'Invalid opening correction',
    ], $headers)->assertStatus(422)->assertJsonValidationErrors('quantity_delta');

    expect(DB::table('inventory_stocks')
        ->where('business_id', $business->id)
        ->where('location_id', $location->id)
        ->where('product_id', $product)
        ->exists())->toBeFalse();

    expect(DB::table('inventory_movements')
        ->where('business_id', $business->id)
        ->where('location_id', $location->id)
        ->where('product_id', $product)
        ->exists())->toBeFalse();
});
