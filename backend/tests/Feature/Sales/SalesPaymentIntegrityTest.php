<?php

use App\Models\Business;
use App\Models\Location;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function spiBusiness(string $timezone = 'Europe/Berlin'): Business
{
    return Business::query()->create([
        'name' => 'Integrity '.Str::random(6),
        'currency' => 'EUR',
        'timezone' => $timezone,
        'status' => 'active',
    ]);
}

function spiLocation(Business $business): Location
{
    return Location::query()->create([
        'business_id' => $business->id,
        'name' => 'Main',
        'code' => 'MAIN-'.Str::upper(Str::random(5)),
        'type' => 'bar',
        'is_active' => true,
    ]);
}

function spiUser(Business $business): User
{
    $user = User::query()->create([
        'name' => 'Integrity Owner',
        'email' => Str::lower(Str::random(12)).'@example.test',
        'password' => bcrypt('password'),
    ]);
    $role = Role::query()->create([
        'business_id' => $business->id,
        'name' => 'Owner',
        'slug' => 'owner-'.Str::lower(Str::random(8)),
        'is_system' => false,
    ]);
    $role->permissions()->sync(Permission::query()->pluck('id'));
    $user->businesses()->attach($business->id, ['role_id' => $role->id, 'status' => 'active']);
    Sanctum::actingAs($user);

    return $user;
}

function spiHeaders(Business $business): array
{
    return ['X-Business-Id' => $business->id];
}

function spiProduct(Business $business, string $price = '10.0000'): string
{
    $id = (string) Str::ulid();
    DB::table('products')->insert([
        'id' => $id,
        'business_id' => $business->id,
        'name' => 'Coffee '.Str::random(5),
        'sale_price' => $price,
        'tax_rate' => '0.0000',
        'tracks_stock' => false,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function spiOrder(Business $business, Location $location, User $user, string $total = '100.0000'): string
{
    $id = (string) Str::ulid();
    DB::table('orders')->insert([
        'id' => $id,
        'business_id' => $business->id,
        'location_id' => $location->id,
        'opened_by_user_id' => $user->id,
        'number' => 'TEST-'.Str::random(8),
        'type' => 'takeaway',
        'status' => 'open',
        'currency' => 'EUR',
        'subtotal' => $total,
        'discount_total' => '0.0000',
        'tax_total' => '0.0000',
        'grand_total' => $total,
        'opened_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

test('new orders reject duplicate product lines even before service normalization', function (): void {
    $business = spiBusiness();
    $location = spiLocation($business);
    spiUser($business);
    $product = spiProduct($business);

    $this->postJson('/api/v1/orders', [
        'location_id' => $location->id,
        'type' => 'takeaway',
        'items' => [
            ['product_id' => $product, 'quantity' => '1.0000'],
            ['product_id' => $product, 'quantity' => '2.0000'],
        ],
    ], spiHeaders($business))->assertStatus(422)->assertJsonValidationErrors(['items.1.product_id']);

    expect(DB::table('orders')->where('business_id', $business->id)->count())->toBe(0);
});

test('order numbers are sequential per business day using the business timezone', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-16 22:30:00', 'UTC'));

    $business = spiBusiness('Europe/Berlin');
    $location = spiLocation($business);
    spiUser($business);
    $product = spiProduct($business);
    $payload = [
        'location_id' => $location->id,
        'type' => 'takeaway',
        'items' => [['product_id' => $product, 'quantity' => '1.0000']],
    ];

    $first = $this->postJson('/api/v1/orders', $payload, spiHeaders($business))->assertCreated();
    $second = $this->postJson('/api/v1/orders', $payload, spiHeaders($business))->assertCreated();

    $first->assertJsonPath('data.number', '20260917-0001');
    $second->assertJsonPath('data.number', '20260917-0002');
    expect(DB::table('business_order_counters')->where('business_id', $business->id)->where('business_date', '2026-09-17')->value('last_number'))->toBe(2);
});

test('payment idempotency replays identical requests and rejects conflicting payloads', function (): void {
    $business = spiBusiness();
    $location = spiLocation($business);
    $user = spiUser($business);
    $order = spiOrder($business, $location, $user);
    $key = 'pay-'.Str::uuid();
    $payload = [
        'location_id' => $location->id,
        'method' => 'card',
        'currency' => 'EUR',
        'amount' => '40.0000',
        'idempotency_key' => $key,
    ];

    $first = $this->postJson("/api/v1/orders/{$order}/payments", $payload, spiHeaders($business))->assertCreated();
    $second = $this->postJson("/api/v1/orders/{$order}/payments", $payload, spiHeaders($business))->assertCreated();
    expect($second->json('data.id'))->toBe($first->json('data.id'));
    expect(DB::table('payments')->where('business_id', $business->id)->where('idempotency_key', $key)->count())->toBe(1);

    $this->postJson("/api/v1/orders/{$order}/payments", [...$payload, 'amount' => '30.0000'], spiHeaders($business))
        ->assertStatus(422)->assertJsonValidationErrors(['idempotency_key']);
});

test('refund idempotency rejects a conflicting replay and net paid balance can be restored', function (): void {
    $business = spiBusiness();
    $location = spiLocation($business);
    $user = spiUser($business);
    $order = spiOrder($business, $location, $user);
    $headers = spiHeaders($business);

    $payment = $this->postJson("/api/v1/orders/{$order}/payments", [
        'location_id' => $location->id,
        'method' => 'card',
        'currency' => 'EUR',
        'amount' => '100.0000',
        'idempotency_key' => 'pay-'.Str::uuid(),
    ], $headers)->assertCreated()->json('data.id');
    expect(DB::table('orders')->where('id', $order)->value('status'))->toBe('paid');

    $refundKey = 'refund-'.Str::uuid();
    $refundPayload = [
        'location_id' => $location->id,
        'amount' => '30.0000',
        'reason' => 'Customer correction',
        'idempotency_key' => $refundKey,
    ];
    $firstRefund = $this->postJson("/api/v1/payments/{$payment}/refunds", $refundPayload, $headers)->assertCreated();
    $replay = $this->postJson("/api/v1/payments/{$payment}/refunds", $refundPayload, $headers)->assertCreated();
    expect($replay->json('data.id'))->toBe($firstRefund->json('data.id'));
    expect(DB::table('orders')->where('id', $order)->value('status'))->toBe('payment_due');

    $this->postJson("/api/v1/payments/{$payment}/refunds", [...$refundPayload, 'reason' => 'Different reason'], $headers)
        ->assertStatus(422)->assertJsonValidationErrors(['idempotency_key']);

    $this->postJson("/api/v1/orders/{$order}/payments", [
        'location_id' => $location->id,
        'method' => 'card',
        'currency' => 'EUR',
        'amount' => '30.0000',
        'idempotency_key' => 'pay-'.Str::uuid(),
    ], $headers)->assertCreated();

    expect(DB::table('orders')->where('id', $order)->value('status'))->toBe('paid');
});


test('foreign currency payment uses inverse rate and enforces the base remaining balance', function (): void {
    $business = spiBusiness();
    $location = spiLocation($business);
    $user = spiUser($business);
    $order = spiOrder($business, $location, $user, '100.0000');
    $headers = spiHeaders($business);

    DB::table('exchange_rates')->insert([
        'base_currency' => 'EUR',
        'quote_currency' => 'USD',
        'rate' => '1.2500000000',
        'source' => 'test',
        'effective_at' => now()->subMinute(),
        'fetched_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $quote = $this->postJson('/api/v1/exchange-rates/convert', [
        'amount' => '125.0000', 'from' => 'USD', 'to' => 'EUR',
    ], $headers)->assertOk();
    expect($quote->json('data.amount'))->toBe('100.0000')
        ->and($quote->json('data.inverse'))->toBeTrue();

    $this->postJson("/api/v1/orders/{$order}/payments", [
        'location_id' => $location->id, 'method' => 'card', 'currency' => 'USD',
        'amount' => '126.0000', 'idempotency_key' => 'pay-'.Str::uuid(),
    ], $headers)->assertStatus(422)->assertJsonValidationErrors(['amount']);

    $payment = $this->postJson("/api/v1/orders/{$order}/payments", [
        'location_id' => $location->id, 'method' => 'card', 'currency' => 'USD',
        'amount' => '125.0000', 'idempotency_key' => 'pay-'.Str::uuid(),
    ], $headers)->assertCreated();

    expect($payment->json('data.amount_base'))->toBe('100.0000')
        ->and(DB::table('orders')->where('id', $order)->value('status'))->toBe('paid');
});


test('cash payment idempotency rejects a changed tendered amount', function (): void {
    $business = spiBusiness();
    $location = spiLocation($business);
    $user = spiUser($business);
    $order = spiOrder($business, $location, $user);
    $headers = spiHeaders($business);

    $registerId = (string) Str::ulid();
    DB::table('cash_registers')->insert([
        'id' => $registerId, 'business_id' => $business->id, 'location_id' => $location->id,
        'name' => 'Main Drawer', 'code' => 'MAIN-DRAWER', 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $session = $this->postJson('/api/v1/cash-sessions', [
        'location_id' => $location->id, 'cash_register_id' => $registerId, 'opening_cash' => '100.0000',
    ], $headers)->assertCreated()->json('data.id');

    $key = 'cash-pay-'.Str::uuid();
    $payload = [
        'location_id' => $location->id, 'cash_session_id' => $session, 'method' => 'cash', 'currency' => 'EUR',
        'amount' => '20.0000', 'tendered_amount' => '50.0000', 'idempotency_key' => $key,
    ];
    $this->postJson("/api/v1/orders/{$order}/payments", $payload, $headers)->assertCreated();
    $this->postJson("/api/v1/orders/{$order}/payments", [...$payload, 'tendered_amount' => '100.0000'], $headers)
        ->assertStatus(422)->assertJsonValidationErrors(['idempotency_key']);
});

test('cash control blocks drawer overdraft and requires a note for closing variance', function (): void {
    $business = spiBusiness();
    $location = spiLocation($business);
    spiUser($business);
    $headers = spiHeaders($business);

    $registerId = (string) Str::ulid();
    DB::table('cash_registers')->insert([
        'id' => $registerId, 'business_id' => $business->id, 'location_id' => $location->id,
        'name' => 'Safe Drawer', 'code' => 'SAFE-DRAWER', 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $session = $this->postJson('/api/v1/cash-sessions', [
        'location_id' => $location->id, 'cash_register_id' => $registerId, 'opening_cash' => '100.0000',
    ], $headers)->assertCreated()->json('data.id');

    $this->postJson("/api/v1/cash-sessions/{$session}/movements", [
        'location_id' => $location->id, 'type' => 'cash_out', 'amount' => '101.0000', 'currency' => 'EUR', 'reason' => 'Supplier payout',
    ], $headers)->assertStatus(422)->assertJsonValidationErrors(['amount']);
    expect(DB::table('cash_movements')->where('cash_session_id', $session)->count())->toBe(0);

    $this->postJson("/api/v1/cash-sessions/{$session}/close", [
        'location_id' => $location->id, 'counted_cash' => '99.0000',
    ], $headers)->assertStatus(422)->assertJsonValidationErrors(['closing_note']);

    $this->postJson("/api/v1/cash-sessions/{$session}/close", [
        'location_id' => $location->id, 'counted_cash' => '99.0000', 'closing_note' => 'One euro short after physical recount',
    ], $headers)->assertOk()->assertJsonPath('data.status', 'closed')
        ->assertJsonPath('data.cash_difference', '-1.0000');
});


test('partial mixed payments settle exactly and refunds reopen only the refunded balance', function (): void {
    $business=spiBusiness();$location=spiLocation($business);$user=spiUser($business);$order=spiOrder($business,$location,$user,'100.0000');$headers=spiHeaders($business);
    $register=(string)Str::ulid();DB::table('cash_registers')->insert(['id'=>$register,'business_id'=>$business->id,'location_id'=>$location->id,'name'=>'Mixed Drawer','code'=>'MIXED','is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
    $session=$this->postJson('/api/v1/cash-sessions',['location_id'=>$location->id,'cash_register_id'=>$register,'opening_cash'=>'50.0000'],$headers)->assertCreated()->json('data.id');
    $cash=$this->postJson("/api/v1/orders/{$order}/payments",['location_id'=>$location->id,'cash_session_id'=>$session,'method'=>'cash','currency'=>'EUR','amount'=>'40.0000','tendered_amount'=>'50.0000','idempotency_key'=>'cash-'.Str::uuid()],$headers)->assertCreated();
    expect($cash->json('data.change_amount'))->toBe('10.0000')->and(DB::table('orders')->where('id',$order)->value('status'))->toBe('payment_due');
    $card=$this->postJson("/api/v1/orders/{$order}/payments",['location_id'=>$location->id,'method'=>'card','currency'=>'EUR','amount'=>'60.0000','idempotency_key'=>'card-'.Str::uuid()],$headers)->assertCreated();
    expect(DB::table('orders')->where('id',$order)->value('status'))->toBe('paid');
    $this->postJson('/api/v1/payments/'.$card->json('data.id').'/refunds',['location_id'=>$location->id,'amount'=>'15.0000','reason'=>'Partial correction','idempotency_key'=>'refund-'.Str::uuid()],$headers)->assertCreated();
    expect(DB::table('orders')->where('id',$order)->value('status'))->toBe('payment_due');
    $this->postJson("/api/v1/orders/{$order}/payments",['location_id'=>$location->id,'method'=>'card','currency'=>'EUR','amount'=>'15.0000','idempotency_key'=>'card-'.Str::uuid()],$headers)->assertCreated();
    expect(DB::table('orders')->where('id',$order)->value('status'))->toBe('paid');
});

test('cash refund cannot overdraw the reconciled drawer balance', function (): void {
    $business=spiBusiness();$location=spiLocation($business);$user=spiUser($business);$order=spiOrder($business,$location,$user,'100.0000');$headers=spiHeaders($business);
    $register=(string)Str::ulid();DB::table('cash_registers')->insert(['id'=>$register,'business_id'=>$business->id,'location_id'=>$location->id,'name'=>'Refund Drawer','code'=>'REFUND','is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
    $session=$this->postJson('/api/v1/cash-sessions',['location_id'=>$location->id,'cash_register_id'=>$register,'opening_cash'=>'0.0000'],$headers)->assertCreated()->json('data.id');
    $payment=$this->postJson("/api/v1/orders/{$order}/payments",['location_id'=>$location->id,'cash_session_id'=>$session,'method'=>'cash','currency'=>'EUR','amount'=>'100.0000','tendered_amount'=>'100.0000','idempotency_key'=>'cash-'.Str::uuid()],$headers)->assertCreated()->json('data.id');
    $this->postJson("/api/v1/cash-sessions/{$session}/movements",['location_id'=>$location->id,'type'=>'cash_out','amount'=>'80.0000','currency'=>'EUR','reason'=>'Safe drop'],$headers)->assertCreated();
    $this->postJson("/api/v1/payments/{$payment}/refunds",['location_id'=>$location->id,'cash_session_id'=>$session,'amount'=>'30.0000','reason'=>'Customer refund','idempotency_key'=>'refund-'.Str::uuid()],$headers)->assertStatus(422)->assertJsonValidationErrors('amount');
    expect(DB::table('payment_refunds')->where('payment_id',$payment)->count())->toBe(0)->and(DB::table('cash_movements')->where('cash_session_id',$session)->where('type','refund')->count())->toBe(0);
});
