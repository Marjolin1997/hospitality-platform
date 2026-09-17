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

function oltContext(): array
{
    $business = Business::query()->create(['name' => 'Lifecycle', 'currency' => 'EUR', 'timezone' => 'Europe/Berlin', 'status' => 'active']);
    $location = Location::query()->create(['business_id' => $business->id, 'name' => 'Main', 'code' => 'MAIN', 'type' => 'bar', 'is_active' => true]);
    $user = User::query()->create(['name' => 'Manager', 'email' => Str::lower(Str::random(10)).'@example.test', 'password' => bcrypt('password')]);
    $role = Role::query()->create(['business_id' => $business->id, 'name' => 'Manager', 'slug' => 'manager-'.Str::lower(Str::random(5)), 'is_system' => false]);
    $role->permissions()->sync(Permission::query()->whereIn('key', ['orders.view', 'orders.create', 'orders.send_to_station', 'orders.cancel'])->pluck('id'));
    $user->businesses()->attach($business->id, ['role_id' => $role->id, 'status' => 'active']);
    Sanctum::actingAs($user);

    return [$business, $location, $user, ['X-Business-Id' => $business->id]];
}

function oltOrder(Business $business, Location $location, User $user, string $status = 'open'): array
{
    $orderId = (string) Str::ulid();
    $itemId = (string) Str::ulid();
    $productId = (string) Str::ulid();

    DB::table('products')->insert([
        'id' => $productId, 'business_id' => $business->id, 'name' => 'Espresso',
        'sale_price' => '3.0000', 'tax_rate' => '0.0000', 'is_active' => true,
        'tracks_stock' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('orders')->insert([
        'id' => $orderId, 'business_id' => $business->id, 'location_id' => $location->id,
        'opened_by_user_id' => $user->id, 'number' => 'ORD-'.Str::random(8), 'type' => 'takeaway',
        'status' => $status, 'currency' => 'EUR', 'subtotal' => '3.0000', 'discount_total' => '0.0000',
        'tax_total' => '0.0000', 'grand_total' => '3.0000', 'opened_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('order_items')->insert([
        'id' => $itemId, 'business_id' => $business->id, 'order_id' => $orderId, 'product_id' => $productId,
        'product_name_snapshot' => 'Espresso', 'sku_snapshot' => null, 'quantity' => '1.0000',
        'unit_price' => '3.0000', 'tax_rate' => '0.0000', 'line_subtotal' => '3.0000',
        'line_tax' => '0.0000', 'line_total' => '3.0000', 'preparation_station' => 'bar',
        'preparation_status' => 'sent', 'sent_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    return [$orderId, $itemId];
}

test('preparation lifecycle is strictly forward only and records timestamps', function (): void {
    [$business, $location, $user, $headers] = oltContext();
    [, $item] = oltOrder($business, $location, $user);

    $this->patchJson('/api/v1/order-items/'.$item.'/preparation', ['status' => 'preparing'], $headers)
        ->assertOk()->assertJsonPath('data.preparation_status', 'preparing');
    expect(DB::table('order_items')->where('id', $item)->value('preparing_at'))->not->toBeNull();

    $this->patchJson('/api/v1/order-items/'.$item.'/preparation', ['status' => 'ready'], $headers)
        ->assertOk()->assertJsonPath('data.preparation_status', 'ready');
    expect(DB::table('order_items')->where('id', $item)->value('prepared_at'))->not->toBeNull();

    $this->patchJson('/api/v1/order-items/'.$item.'/preparation', ['status' => 'served'], $headers)
        ->assertOk()->assertJsonPath('data.preparation_status', 'served');
    expect(DB::table('order_items')->where('id', $item)->value('served_at'))->not->toBeNull();

    $this->patchJson('/api/v1/order-items/'.$item.'/preparation', ['status' => 'ready'], $headers)->assertStatus(422);
});

test('item cancellation requires reason and is auditable', function (): void {
    [$business, $location, $user, $headers] = oltContext();
    [, $item] = oltOrder($business, $location, $user);

    $this->postJson('/api/v1/order-items/'.$item.'/cancel', [], $headers)->assertStatus(422);
    $this->postJson('/api/v1/order-items/'.$item.'/cancel', ['reason' => 'Guest changed order'], $headers)
        ->assertOk()->assertJsonPath('data.preparation_status', 'voided');

    $row = DB::table('order_items')->where('id', $item)->first();
    expect($row->void_reason)->toBe('Guest changed order')
        ->and((int) $row->voided_by_user_id)->toBe((int) $user->id)
        ->and($row->voided_at)->not->toBeNull();
});

test('order cancellation voids remaining items and records actor and reason', function (): void {
    [$business, $location, $user, $headers] = oltContext();
    [$order, $item] = oltOrder($business, $location, $user);

    $this->postJson('/api/v1/orders/'.$order.'/cancel', ['reason' => 'Guest cancelled entire order'], $headers)
        ->assertOk()->assertJsonPath('data.status', 'cancelled');

    $row = DB::table('orders')->where('id', $order)->first();
    expect($row->cancel_reason)->toBe('Guest cancelled entire order')
        ->and((int) $row->cancelled_by_user_id)->toBe((int) $user->id)
        ->and($row->cancelled_at)->not->toBeNull();
    expect(DB::table('order_items')->where('id', $item)->value('preparation_status'))->toBe('voided');
});

test('orders with completed payments cannot be cancelled directly', function (): void {
    [$business, $location, $user, $headers] = oltContext();
    [$order] = oltOrder($business, $location, $user, 'payment_due');

    DB::table('payments')->insert([
        'id' => (string) Str::ulid(), 'business_id' => $business->id, 'order_id' => $order,
        'collected_by_user_id' => $user->id, 'method' => 'card', 'status' => 'completed',
        'amount' => '1.0000', 'currency' => 'EUR', 'amount_base' => '1.0000', 'base_currency' => 'EUR',
        'exchange_rate' => '1.0000000000', 'idempotency_key' => 'p-'.Str::uuid(), 'paid_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->postJson('/api/v1/orders/'.$order.'/cancel', ['reason' => 'Cancel after payment'], $headers)->assertStatus(422);
    expect(DB::table('orders')->where('id', $order)->value('status'))->toBe('payment_due');
});

test('lifecycle endpoints cannot cross tenant boundaries', function (): void {
    [$business, $location, $user, $headers] = oltContext();
    $foreign = Business::query()->create(['name' => 'Foreign', 'currency' => 'EUR', 'timezone' => 'UTC', 'status' => 'active']);
    $foreignLocation = Location::query()->create(['business_id' => $foreign->id, 'name' => 'Other', 'code' => 'OTHER', 'type' => 'bar', 'is_active' => true]);
    $foreignUser = User::query()->create(['name' => 'Foreign', 'email' => Str::lower(Str::random(10)).'@example.test', 'password' => bcrypt('password')]);
    [$order, $item] = oltOrder($foreign, $foreignLocation, $foreignUser);

    $this->postJson('/api/v1/orders/'.$order.'/cancel', ['reason' => 'Cross tenant attempt'], $headers)->assertNotFound();
    $this->patchJson('/api/v1/order-items/'.$item.'/preparation', ['status' => 'preparing'], $headers)->assertNotFound();
    $this->postJson('/api/v1/order-items/'.$item.'/cancel', ['reason' => 'Cross tenant attempt'], $headers)->assertNotFound();
});
