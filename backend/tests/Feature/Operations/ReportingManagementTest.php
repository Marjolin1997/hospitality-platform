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

function rptBusiness(string $name): Business
{
    return Business::query()->create([
        'name' => $name,
        'currency' => 'EUR',
        'timezone' => 'Europe/Berlin',
        'status' => 'active',
    ]);
}

function rptLocation(Business $business, string $name = 'Main'): Location
{
    return Location::query()->create([
        'business_id' => $business->getKey(),
        'name' => $name,
        'code' => Str::upper(Str::substr(Str::slug($name, ''), 0, 10)).Str::upper(Str::random(3)),
        'type' => 'bar_cafe',
        'is_active' => true,
    ]);
}

function rptUser(Business $business, array $permissions): User
{
    $user = User::query()->create([
        'name' => 'Report User',
        'email' => Str::lower(Str::random(12)).'@example.test',
        'password' => 'Reports#Password123',
    ]);

    $role = Role::query()->create([
        'business_id' => $business->getKey(),
        'name' => 'Report Role '.Str::random(5),
        'slug' => 'report-role-'.Str::lower(Str::random(8)),
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

function rptHeaders(User $user, Business $business): array
{
    Sanctum::actingAs($user);

    return ['X-Business-Id' => $business->getKey()];
}

function rptDate(Business $business): string
{
    return now($business->timezone)->toDateString();
}

function rptOrder(
    Business $business,
    Location $location,
    User $user,
    string $number,
    string $status,
    string $total,
): string {
    $id = (string) Str::ulid();

    DB::table('orders')->insert([
        'id' => $id,
        'business_id' => $business->id,
        'location_id' => $location->id,
        'venue_table_id' => null,
        'opened_by_user_id' => $user->id,
        'number' => $number,
        'type' => 'counter',
        'status' => $status,
        'currency' => 'EUR',
        'subtotal' => $total,
        'discount_total' => '0.0000',
        'tax_total' => '0.0000',
        'grand_total' => $total,
        'opened_at' => now(),
        'closed_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function rptPayment(
    Business $business,
    string $orderId,
    User $user,
    string $amount,
    string $key,
    string $method = 'card',
): string {
    $id = (string) Str::ulid();

    DB::table('payments')->insert([
        'id' => $id,
        'business_id' => $business->id,
        'order_id' => $orderId,
        'cash_session_id' => null,
        'collected_by_user_id' => $user->id,
        'method' => $method,
        'status' => 'completed',
        'amount' => $amount,
        'amount_base' => $amount,
        'currency' => 'EUR',
        'base_currency' => 'EUR',
        'exchange_rate' => '1.0000000000',
        'tendered_amount' => null,
        'change_amount' => null,
        'exchange_rate_snapshot' => null,
        'idempotency_key' => $key,
        'external_reference' => null,
        'paid_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

test('operational report derives payment refund order and product metrics from transactional data', function (): void {
    $business = rptBusiness('Operational Reports');
    $location = rptLocation($business);
    $user = rptUser($business, ['reports.operational.view']);
    $headers = rptHeaders($user, $business);

    $product = (string) Str::ulid();
    DB::table('products')->insert([
        'id' => $product,
        'business_id' => $business->id,
        'product_category_id' => null,
        'name' => 'Report Coffee',
        'sku' => 'RPT-COFFEE',
        'barcode' => null,
        'sale_price' => '50.0000',
        'tax_rate' => '0.0000',
        'unit_code' => 'C62',
        'unit_label' => 'pcs',
        'preparation_station' => null,
        'tracks_stock' => false,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $order = rptOrder($business, $location, $user, 'RPT-OP-1', 'partially_refunded', '100.0000');

    DB::table('order_items')->insert([
        'id' => (string) Str::ulid(),
        'business_id' => $business->id,
        'order_id' => $order,
        'product_id' => $product,
        'product_name_snapshot' => 'Report Coffee',
        'sku_snapshot' => 'RPT-COFFEE',
        'quantity' => '2.0000',
        'unit_price' => '50.0000',
        'tax_rate' => '0.0000',
        'line_subtotal' => '100.0000',
        'line_tax' => '0.0000',
        'line_total' => '100.0000',
        'preparation_station' => null,
        'preparation_status' => 'served',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $payment = rptPayment($business, $order, $user, '100.0000', 'rpt-payment-1');

    DB::table('payment_refunds')->insert([
        'id' => (string) Str::ulid(),
        'business_id' => $business->id,
        'payment_id' => $payment,
        'invoice_credit_note_id' => null,
        'cash_session_id' => null,
        'refunded_by_user_id' => $user->id,
        'amount' => '20.0000',
        'amount_base' => '20.0000',
        'currency' => 'EUR',
        'base_currency' => 'EUR',
        'exchange_rate' => '1.0000000000',
        'reason' => 'Report test refund',
        'idempotency_key' => 'rpt-refund-1',
        'status' => 'completed',
        'refunded_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $date = rptDate($business);

    $report = $this->getJson(
        "/api/v1/reports/operational?location_id={$location->id}&from={$date}&to={$date}",
        $headers,
    )->assertOk()
        ->assertJsonPath('data.summary.gross_sales', '100.0000')
        ->assertJsonPath('data.summary.refunds', '20.0000')
        ->assertJsonPath('data.summary.net_sales', '80.0000')
        ->assertJsonPath('data.summary.paid_order_count', 1)
        ->assertJsonPath('data.summary.average_ticket', '80.0000')
        ->assertJsonPath('data.summary.orders_opened', 1)
        ->json('data');

    expect($report['payment_mix'][0]['method'])->toBe('card')
        ->and($report['payment_mix'][0]['net_amount'])->toBe('80.0000')
        ->and($report['product_mix'][0]['product_name'])->toBe('Report Coffee')
        ->and($report['product_mix'][0]['quantity'])->toBe('2.0000')
        ->and($report['product_mix'][0]['gross_line_value'])->toBe('100.0000')
        ->and($report['staff_activity'][0]['user_id'])->toBe($user->id)
        ->and($report['staff_activity'][0]['orders_opened'])->toBe(1);
});

test('financial report separates expense reversals unallocated expenses cash variance and received procurement cost', function (): void {
    $business = rptBusiness('Financial Reports');
    $location = rptLocation($business);
    $user = rptUser($business, [
        'reports.financial.view',
        'purchasing.view',
        'purchasing.manage',
        'inventory.receive',
        'products.view',
        'products.manage',
    ]);
    $headers = rptHeaders($user, $business);

    $order = rptOrder($business, $location, $user, 'RPT-FIN-1', 'paid', '200.0000');
    $payment = rptPayment($business, $order, $user, '200.0000', 'rpt-fin-payment');

    DB::table('payment_refunds')->insert([
        'id' => (string) Str::ulid(),
        'business_id' => $business->id,
        'payment_id' => $payment,
        'invoice_credit_note_id' => null,
        'cash_session_id' => null,
        'refunded_by_user_id' => $user->id,
        'amount' => '20.0000',
        'amount_base' => '20.0000',
        'currency' => 'EUR',
        'base_currency' => 'EUR',
        'exchange_rate' => '1.0000000000',
        'reason' => 'Financial report refund',
        'idempotency_key' => 'rpt-fin-refund',
        'status' => 'completed',
        'refunded_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $date = rptDate($business);
    $postedExpenseId = (string) Str::ulid();
    $reversedExpenseId = (string) Str::ulid();

    DB::table('expenses')->insert([
        [
            'id' => $postedExpenseId,
            'business_id' => $business->id,
            'location_id' => $location->id,
            'created_by_user_id' => $user->id,
            'category' => 'Utilities',
            'description' => 'Electricity',
            'amount' => '30.0000',
            'currency' => 'EUR',
            'expense_date' => $date,
            'status' => 'posted',
            'reversal_of_expense_id' => null,
            'reversed_by_user_id' => null,
            'reversal_reason' => null,
            'reversed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => $reversedExpenseId,
            'business_id' => $business->id,
            'location_id' => $location->id,
            'created_by_user_id' => $user->id,
            'category' => 'Supplies',
            'description' => 'Wrong charge',
            'amount' => '50.0000',
            'currency' => 'EUR',
            'expense_date' => $date,
            'status' => 'reversed',
            'reversal_of_expense_id' => null,
            'reversed_by_user_id' => $user->id,
            'reversal_reason' => 'Corrected',
            'reversed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    DB::table('expenses')->insert([
        'id' => (string) Str::ulid(),
        'business_id' => $business->id,
        'location_id' => $location->id,
        'created_by_user_id' => $user->id,
        'category' => 'Supplies',
        'description' => 'Reversal: Wrong charge',
        'amount' => '50.0000',
        'currency' => 'EUR',
        'expense_date' => $date,
        'status' => 'reversal',
        'reversal_of_expense_id' => $reversedExpenseId,
        'reversed_by_user_id' => $user->id,
        'reversal_reason' => 'Corrected',
        'reversed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('expenses')->insert([
        'id' => (string) Str::ulid(),
        'business_id' => $business->id,
        'location_id' => null,
        'created_by_user_id' => $user->id,
        'category' => 'Software',
        'description' => 'Business-wide SaaS',
        'amount' => '10.0000',
        'currency' => 'EUR',
        'expense_date' => $date,
        'status' => 'posted',
        'reversal_of_expense_id' => null,
        'reversed_by_user_id' => null,
        'reversal_reason' => null,
        'reversed_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $registerId = (string) Str::ulid();
    DB::table('cash_registers')->insert([
        'id' => $registerId,
        'business_id' => $business->id,
        'location_id' => $location->id,
        'name' => 'Report Till',
        'code' => 'RPT-TILL',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('cash_sessions')->insert([
        'id' => (string) Str::ulid(),
        'business_id' => $business->id,
        'location_id' => $location->id,
        'cash_register_id' => $registerId,
        'opened_by_user_id' => $user->id,
        'closed_by_user_id' => $user->id,
        'base_currency' => 'EUR',
        'opening_cash' => '100.0000',
        'expected_cash' => '200.0000',
        'counted_cash' => '195.0000',
        'cash_difference' => '-5.0000',
        'status' => 'closed',
        'opened_at' => now()->subHour(),
        'closed_at' => now(),
        'closing_note' => 'Report test variance',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $product = $this->postJson('/api/v1/management/products', [
        'name' => 'Report Stock Item',
        'category_id' => null,
        'sku' => 'RPT-STOCK',
        'sale_price' => '8',
        'tax_rate' => '20',
        'unit_code' => 'C62',
        'unit_label' => 'pcs',
        'preparation_station' => null,
        'tracks_stock' => true,
        'is_active' => true,
    ], $headers)->assertCreated()->json('data.id');

    $supplier = $this->postJson('/api/v1/purchasing/suppliers', [
        'name' => 'Report Supplier',
    ], $headers)->assertCreated()->json('data.id');

    $po = $this->postJson('/api/v1/purchase-orders', [
        'location_id' => $location->id,
        'supplier_id' => $supplier,
        'items' => [[
            'product_id' => $product,
            'quantity_ordered' => '3',
            'unit_cost' => '4',
        ]],
    ], $headers)->assertCreated()->json('data.id');

    $this->postJson("/api/v1/purchase-orders/{$po}/place", [], $headers)->assertOk();
    $poLine = DB::table('purchase_order_items')->where('purchase_order_id', $po)->firstOrFail();

    $this->postJson("/api/v1/purchase-orders/{$po}/receipts", [
        'items' => [[
            'purchase_order_item_id' => $poLine->id,
            'quantity_received' => '3',
        ]],
    ], $headers)->assertCreated();

    $report = $this->getJson(
        "/api/v1/reports/financial?location_id={$location->id}&from={$date}&to={$date}",
        $headers,
    )->assertOk()
        ->assertJsonPath('data.summary.gross_sales', '200.0000')
        ->assertJsonPath('data.summary.refunds', '20.0000')
        ->assertJsonPath('data.summary.net_sales', '180.0000')
        ->assertJsonPath('data.summary.location_expenses', '30.0000')
        ->assertJsonPath('data.summary.unallocated_business_expenses', '10.0000')
        ->assertJsonPath('data.summary.net_after_location_expenses', '150.0000')
        ->assertJsonPath('data.summary.goods_receipt_count', 1)
        ->assertJsonPath('data.summary.goods_received_cost', '12.0000')
        ->assertJsonPath('data.cash_reconciliation.closed_sessions', 1)
        ->assertJsonPath('data.cash_reconciliation.cash_short', '5.0000')
        ->assertJsonPath('data.cash_reconciliation.net_variance', '-5.0000')
        ->json('data');

    expect(collect($report['expense_categories'])->firstWhere('category', 'Utilities')['net_amount'])->toBe('30.0000')
        ->and(collect($report['expense_categories'])->firstWhere('category', 'Supplies')['net_amount'])->toBe('0.0000');
});

test('report permissions date limits and tenant location scope are enforced', function (): void {
    $a = rptBusiness('Report Tenant A');
    $b = rptBusiness('Report Tenant B');
    $locationA = rptLocation($a, 'A');
    $locationB = rptLocation($b, 'B');

    $operational = rptUser($a, ['reports.operational.view']);
    $financial = rptUser($a, ['reports.financial.view']);

    $date = rptDate($a);

    $opHeaders = rptHeaders($operational, $a);
    $this->getJson(
        "/api/v1/reports/operational?location_id={$locationA->id}&from={$date}&to={$date}",
        $opHeaders,
    )->assertOk();

    $this->getJson(
        "/api/v1/reports/financial?location_id={$locationA->id}&from={$date}&to={$date}",
        $opHeaders,
    )->assertForbidden();

    $finHeaders = rptHeaders($financial, $a);
    $this->getJson(
        "/api/v1/reports/operational?location_id={$locationA->id}&from={$date}&to={$date}",
        $finHeaders,
    )->assertForbidden();

    $this->getJson(
        "/api/v1/reports/financial?location_id={$locationB->id}&from={$date}&to={$date}",
        $finHeaders,
    )->assertStatus(422)
        ->assertJsonValidationErrors('location_id');

    $this->getJson(
        "/api/v1/reports/financial?location_id={$locationA->id}&from=2025-01-01&to=2026-01-03",
        $finHeaders,
    )->assertStatus(422)
        ->assertJsonValidationErrors('to');

    $this->getJson(
        "/api/v1/reports/financial?location_id={$locationA->id}&from=2026-02-01&to=2026-01-01",
        $finHeaders,
    )->assertStatus(422)
        ->assertJsonValidationErrors('to');
});
