<?php

use App\Models\Business;
use App\Models\Location;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleTemplateSeeder::class);
});

function teiBusiness(string $name): Business
{
    return Business::query()->create([
        'name' => $name,
        'currency' => 'EUR',
        'timezone' => 'Europe/Berlin',
        'status' => 'active',
    ]);
}

function teiLocation(Business $business, string $code): Location
{
    return Location::query()->create([
        'business_id' => $business->getKey(),
        'name' => "Location {$code}",
        'code' => $code,
        'type' => 'bar',
        'is_active' => true,
    ]);
}

function teiUserWithAllPermissions(Business $business, string $email): User
{
    $user = User::query()->create([
        'name' => 'Tenant Isolation User',
        'email' => $email,
        'password' => bcrypt('password'),
    ]);

    $role = Role::query()->create([
        'business_id' => $business->getKey(),
        'name' => 'Isolation Owner',
        'slug' => 'isolation-owner',
        'is_system' => false,
    ]);

    $role->permissions()->sync(Permission::query()->pluck('id'));

    $user->businesses()->attach($business->getKey(), [
        'role_id' => $role->getKey(),
        'status' => 'active',
    ]);

    return $user;
}

function teiActingAs(User $user, Business $business): array
{
    Sanctum::actingAs($user);

    return ['X-Business-Id' => $business->getKey()];
}

function teiCategory(Business $business, string $name): string
{
    $id = (string) Str::ulid();
    DB::table('product_categories')->insert([
        'id' => $id,
        'business_id' => $business->getKey(),
        'name' => $name,
        'sort_order' => 0,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    return $id;
}

function teiProduct(Business $business, ?string $categoryId, string $name): string
{
    $id = (string) Str::ulid();
    DB::table('products')->insert([
        'id' => $id,
        'business_id' => $business->getKey(),
        'product_category_id' => $categoryId,
        'name' => $name,
        'sku' => 'SKU-'.Str::random(10),
        'sale_price' => '5.0000',
        'tax_rate' => '0.0000',
        'is_active' => true,
        'tracks_stock' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    return $id;
}

function teiArea(Business $business, Location $location, string $name): string
{
    $id = (string) Str::ulid();
    DB::table('venue_areas')->insert([
        'id' => $id,
        'business_id' => $business->getKey(),
        'location_id' => $location->getKey(),
        'name' => $name,
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    return $id;
}

function teiTable(Business $business, Location $location, ?string $areaId, string $name): string
{
    $id = (string) Str::ulid();
    DB::table('venue_tables')->insert([
        'id' => $id,
        'business_id' => $business->getKey(),
        'location_id' => $location->getKey(),
        'venue_area_id' => $areaId,
        'name' => $name,
        'capacity' => 2,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    return $id;
}

function teiOrder(Business $business, Location $location, User $user, string $number): string
{
    $id = (string) Str::ulid();
    DB::table('orders')->insert([
        'id' => $id,
        'business_id' => $business->getKey(),
        'location_id' => $location->getKey(),
        'opened_by_user_id' => $user->getKey(),
        'number' => $number,
        'type' => 'takeaway',
        'status' => 'open',
        'currency' => 'EUR',
        'subtotal' => '10.0000',
        'discount_total' => '0.0000',
        'tax_total' => '0.0000',
        'grand_total' => '10.0000',
        'opened_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    return $id;
}

function teiOrderItem(Business $business, string $orderId, string $name, string $status = 'sent'): string
{
    $id = (string) Str::ulid();
    DB::table('order_items')->insert([
        'id' => $id,
        'business_id' => $business->getKey(),
        'order_id' => $orderId,
        'product_name_snapshot' => $name,
        'quantity' => '1.0000',
        'unit_price' => '10.0000',
        'tax_rate' => '0.0000',
        'line_subtotal' => '10.0000',
        'line_tax' => '0.0000',
        'line_total' => '10.0000',
        'preparation_station' => 'bar',
        'preparation_status' => $status,
        'sent_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    return $id;
}

function teiCashRegister(Business $business, Location $location, string $code): string
{
    $id = (string) Str::ulid();
    DB::table('cash_registers')->insert([
        'id' => $id,
        'business_id' => $business->getKey(),
        'location_id' => $location->getKey(),
        'name' => "Register {$code}",
        'code' => $code,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    return $id;
}

function teiCashSession(Business $business, Location $location, User $user, string $registerId): string
{
    $id = (string) Str::ulid();
    DB::table('cash_sessions')->insert([
        'id' => $id,
        'business_id' => $business->getKey(),
        'location_id' => $location->getKey(),
        'cash_register_id' => $registerId,
        'opened_by_user_id' => $user->getKey(),
        'base_currency' => 'EUR',
        'opening_cash' => '100.0000',
        'status' => 'open',
        'opened_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    return $id;
}

function teiPayment(Business $business, string $orderId, User $user): string
{
    $id = (string) Str::ulid();
    DB::table('payments')->insert([
        'id' => $id,
        'business_id' => $business->getKey(),
        'order_id' => $orderId,
        'cash_session_id' => null,
        'collected_by_user_id' => $user->getKey(),
        'method' => 'card',
        'status' => 'completed',
        'amount' => '10.0000',
        'amount_base' => '10.0000',
        'currency' => 'EUR',
        'base_currency' => 'EUR',
        'exchange_rate' => '1.0000000000',
        'idempotency_key' => 'payment-'.Str::random(16),
        'paid_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    return $id;
}

test('catalog returns only categories belonging to the active business', function (): void {
    $a = teiBusiness('Business A'); $b = teiBusiness('Business B');
    $user = teiUserWithAllPermissions($a, 'catalog-scope@example.test');
    $categoryA = teiCategory($a, 'A Coffee');
    teiProduct($a, $categoryA, 'A Espresso');
    $categoryB = teiCategory($b, 'B Secret Category');
    teiProduct($b, $categoryB, 'B Secret Product');

    $this->withHeaders(teiActingAs($user, $a))->getJson('/api/v1/catalog')
        ->assertOk()->assertJsonFragment(['name' => 'A Coffee'])
        ->assertJsonMissing(['name' => 'B Secret Category'])
        ->assertJsonMissing(['name' => 'B Secret Product']);
});

test('catalog nested products cannot cross tenant boundary even with inconsistent foreign keys', function (): void {
    $a = teiBusiness('Business A'); $b = teiBusiness('Business B');
    $user = teiUserWithAllPermissions($a, 'catalog-nested@example.test');
    $categoryA = teiCategory($a, 'A Category');
    teiProduct($b, $categoryA, 'B Product Attached To A Category');

    $this->withHeaders(teiActingAs($user, $a))->getJson('/api/v1/catalog')
        ->assertOk()->assertJsonMissing(['name' => 'B Product Attached To A Category']);
});

test('venue returns only areas belonging to the active business', function (): void {
    $a = teiBusiness('Business A'); $b = teiBusiness('Business B');
    $la = teiLocation($a, 'A1'); $lb = teiLocation($b, 'B1');
    $user = teiUserWithAllPermissions($a, 'venue-scope@example.test');
    teiArea($a, $la, 'A Main Room'); teiArea($b, $lb, 'B Secret Room');

    $this->withHeaders(teiActingAs($user, $a))->getJson('/api/v1/venue?location_id='.$la->getKey())
        ->assertOk()->assertJsonFragment(['name' => 'A Main Room'])
        ->assertJsonMissing(['name' => 'B Secret Room']);
});

test('venue nested tables cannot cross tenant boundary even with inconsistent foreign keys', function (): void {
    $a = teiBusiness('Business A'); $b = teiBusiness('Business B');
    $la = teiLocation($a, 'A1'); $lb = teiLocation($b, 'B1');
    $user = teiUserWithAllPermissions($a, 'venue-nested@example.test');
    $areaA = teiArea($a, $la, 'A Area');
    teiTable($b, $lb, $areaA, 'B Table Attached To A Area');

    $this->withHeaders(teiActingAs($user, $a))->getJson('/api/v1/venue?location_id='.$la->getKey())
        ->assertOk()->assertJsonMissing(['name' => 'B Table Attached To A Area']);
});

test('orders index never returns orders from another business', function (): void {
    $a = teiBusiness('Business A'); $b = teiBusiness('Business B');
    $la = teiLocation($a, 'A1'); $lb = teiLocation($b, 'B1');
    $user = teiUserWithAllPermissions($a, 'orders-index@example.test');
    teiOrder($a, $la, $user, 'A-ORDER-1'); teiOrder($b, $lb, $user, 'B-SECRET-1');

    $this->withHeaders(teiActingAs($user, $a))->getJson('/api/v1/orders')
        ->assertOk()->assertJsonFragment(['number' => 'A-ORDER-1'])
        ->assertJsonMissing(['number' => 'B-SECRET-1']);
});

test('order show returns 404 for an order from another business', function (): void {
    $a = teiBusiness('Business A'); $b = teiBusiness('Business B');
    $lb = teiLocation($b, 'B1');
    $user = teiUserWithAllPermissions($a, 'orders-show@example.test');
    $orderB = teiOrder($b, $lb, $user, 'B-SECRET-2');

    $this->withHeaders(teiActingAs($user, $a))->getJson('/api/v1/orders/'.$orderB)->assertNotFound();
});

test('sending another business order is rejected as not found', function (): void {
    $a = teiBusiness('Business A'); $b = teiBusiness('Business B');
    $lb = teiLocation($b, 'B1');
    $user = teiUserWithAllPermissions($a, 'orders-send@example.test');
    $orderB = teiOrder($b, $lb, $user, 'B-SECRET-3');

    $this->withHeaders(teiActingAs($user, $a))->postJson('/api/v1/orders/'.$orderB.'/send', [])->assertNotFound();
});

test('bar queue never returns items from another business', function (): void {
    $a = teiBusiness('Business A'); $b = teiBusiness('Business B');
    $la = teiLocation($a, 'A1'); $lb = teiLocation($b, 'B1');
    $user = teiUserWithAllPermissions($a, 'bar-index@example.test');
    $oa = teiOrder($a, $la, $user, 'A-BAR-1'); $ob = teiOrder($b, $lb, $user, 'B-BAR-1');
    teiOrderItem($a, $oa, 'A Visible Drink'); teiOrderItem($b, $ob, 'B Secret Drink');

    $this->withHeaders(teiActingAs($user, $a))->getJson('/api/v1/bar-queue?location_id='.$la->getKey())
        ->assertOk()->assertJsonFragment(['product_name_snapshot' => 'A Visible Drink'])
        ->assertJsonMissing(['product_name_snapshot' => 'B Secret Drink']);
});

test('bar transition cannot mutate another business order item', function (): void {
    $a = teiBusiness('Business A'); $b = teiBusiness('Business B');
    $lb = teiLocation($b, 'B1');
    $user = teiUserWithAllPermissions($a, 'bar-transition@example.test');
    $ob = teiOrder($b, $lb, $user, 'B-BAR-2'); $itemB = teiOrderItem($b, $ob, 'B Protected Drink');

    $this->withHeaders(teiActingAs($user, $a))->patchJson('/api/v1/bar-queue/'.$itemB.'/status', [
        'status' => 'preparing', 'location_id' => $lb->getKey(),
    ])->assertNotFound();

    expect(DB::table('order_items')->where('id', $itemB)->value('preparation_status'))->toBe('sent');
});

test('cash register listing cannot expose registers from another business', function (): void {
    $a = teiBusiness('Business A'); $b = teiBusiness('Business B');
    $la = teiLocation($a, 'A1'); $lb = teiLocation($b, 'B1');
    $user = teiUserWithAllPermissions($a, 'registers@example.test');
    teiCashRegister($a, $la, 'A-REG'); teiCashRegister($b, $lb, 'B-SECRET-REG');

    $this->withHeaders(teiActingAs($user, $a))->getJson('/api/v1/cash-registers?location_id='.$la->getKey())
        ->assertOk()->assertJsonFragment(['code' => 'A-REG'])
        ->assertJsonMissing(['code' => 'B-SECRET-REG']);
});

test('current cash session cannot expose another business session', function (): void {
    $a = teiBusiness('Business A'); $b = teiBusiness('Business B');
    $la = teiLocation($a, 'A1'); $lb = teiLocation($b, 'B1');
    $user = teiUserWithAllPermissions($a, 'session-current@example.test');
    $registerB = teiCashRegister($b, $lb, 'B-REG');
    teiCashSession($b, $lb, $user, $registerB);

    $this->withHeaders(teiActingAs($user, $a))->getJson('/api/v1/cash-sessions/current?location_id='.$la->getKey())
        ->assertOk()->assertJsonPath('data', null);
});

test('collect payment cannot target an order from another business', function (): void {
    $a = teiBusiness('Business A'); $b = teiBusiness('Business B');
    $lb = teiLocation($b, 'B1');
    $user = teiUserWithAllPermissions($a, 'collect-cross@example.test');
    $orderB = teiOrder($b, $lb, $user, 'B-PAY-1');

    $this->withHeaders(teiActingAs($user, $a))->postJson('/api/v1/orders/'.$orderB.'/payments?location_id='.$lb->getKey(), [
        'method' => 'card', 'currency' => 'EUR', 'amount' => '10.00', 'idempotency_key' => 'cross-order-payment',
    ])->assertNotFound();

    expect(DB::table('payments')->where('order_id', $orderB)->count())->toBe(0);
});

test('refund cannot target a payment from another business', function (): void {
    $a = teiBusiness('Business A'); $b = teiBusiness('Business B');
    $lb = teiLocation($b, 'B1');
    $user = teiUserWithAllPermissions($a, 'refund-cross@example.test');
    $orderB = teiOrder($b, $lb, $user, 'B-PAY-2');
    $paymentB = teiPayment($b, $orderB, $user);

    $this->withHeaders(teiActingAs($user, $a))->postJson('/api/v1/payments/'.$paymentB.'/refunds?location_id='.$lb->getKey(), [
        'amount' => '5.00', 'reason' => 'Cross tenant attempt', 'idempotency_key' => 'cross-payment-refund',
    ])->assertNotFound();

    expect(DB::table('payment_refunds')->where('payment_id', $paymentB)->count())->toBe(0);
});
