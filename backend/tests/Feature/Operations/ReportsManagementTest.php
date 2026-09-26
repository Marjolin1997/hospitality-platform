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

function rpmBusiness(string $name): Business
{
    return Business::query()->create([
        'name' => $name,
        'currency' => 'EUR',
        'timezone' => 'Europe/Berlin',
        'status' => 'active',
    ]);
}

function rpmLocation(Business $business, string $name): Location
{
    return Location::query()->create([
        'business_id' => $business->getKey(),
        'name' => $name,
        'code' => Str::upper(Str::substr(Str::slug($name, ''), 0, 10)).Str::upper(Str::random(3)),
        'type' => 'bar_cafe',
        'is_active' => true,
    ]);
}

function rpmUser(Business $business, array $permissions, string $name = 'Report User'): User
{
    $user = User::query()->create([
        'name' => $name,
        'email' => Str::lower(Str::random(12)).'@example.test',
        'password' => 'Reports#Password123',
    ]);

    $role = Role::query()->create([
        'business_id' => $business->getKey(),
        'name' => 'Report Role '.Str::random(5),
        'slug' => 'report-'.Str::lower(Str::random(8)),
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

function rpmHeaders(User $user, Business $business): array
{
    Sanctum::actingAs($user);

    return ['X-Business-Id' => $business->getKey()];
}

function rpmOrder(
    Business $business,
    Location $location,
    User $user,
    string $number,
    string $status,
    string $total,
    ?string $cancelledAt = null,
): string {
    $id = (string) Str::ulid();

    DB::table('orders')->insert([
        'id' => $id,
        'business_id' => $business->getKey(),
        'location_id' => $location->getKey(),
        'opened_by_user_id' => $user->getKey(),
        'number' => $number,
        'type' => 'table',
        'status' => $status,
        'currency' => $business->currency,
        'subtotal' => $total,
        'discount_total' => '0.0000',
        'tax_total' => '0.0000',
        'grand_total' => $total,
        'opened_at' => now(),
        'cancelled_at' => $cancelledAt,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

test('operational report uses transaction timestamps and tenant location scope', function (): void {
    $business = rpmBusiness('Operational Reports');
    $main = rpmLocation($business, 'Main');
    $other = rpmLocation($business, 'Other');
    $user = rpmUser($business, ['reports.operational.view'], 'Operator One');
    $headers = rpmHeaders($user, $business);
    $date = now($business->timezone)->toDateString();

    $paidOrder = rpmOrder($business, $main, $user, 'ORD-RPT-1', 'paid', '100.0000');

    DB::table('orders')->where('id', $paidOrder)->update([
        'discount_total' => '10.0000',
        'discount_reason' => 'Report discount',
        'discount_applied_by_user_id' => $user->id,
        'discount_applied_at' => now(),
    ]);

    DB::table('order_items')->insert([
        [
            'id' => (string) Str::ulid(),
            'business_id' => $business->id,
            'order_id' => $paidOrder,
            'product_id' => null,
            'product_name_snapshot' => 'Espresso',
            'sku_snapshot' => 'ESP',
            'quantity' => '2.0000',
            'unit_price' => '50.0000',
            'tax_rate' => '0.0000',
            'line_subtotal' => '100.0000',
            'line_tax' => '0.0000',
            'line_total' => '100.0000',
            'preparation_status' => 'served',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => (string) Str::ulid(),
            'business_id' => $business->id,
            'order_id' => $paidOrder,
            'product_id' => null,
            'product_name_snapshot' => 'Croissant',
            'sku_snapshot' => 'CRO',
            'quantity' => '1.0000',
            'unit_price' => '20.0000',
            'tax_rate' => '0.0000',
            'line_subtotal' => '20.0000',
            'line_tax' => '0.0000',
            'line_total' => '20.0000',
            'preparation_status' => 'voided',
            'void_reason' => 'Customer changed mind',
            'voided_by_user_id' => $user->id,
            'voided_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $payment = (string) Str::ulid();
    DB::table('payments')->insert([
        'id' => $payment,
        'business_id' => $business->id,
        'order_id' => $paidOrder,
        'cash_session_id' => null,
        'collected_by_user_id' => $user->id,
        'method' => 'cash',
        'status' => 'completed',
        'amount' => '100.0000',
        'amount_base' => '100.0000',
        'currency' => 'EUR',
        'base_currency' => 'EUR',
        'exchange_rate' => '1.0000000000',
        'idempotency_key' => 'report-payment-1',
        'paid_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

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
        'reason' => 'Partial refund',
        'idempotency_key' => 'report-refund-1',
        'status' => 'completed',
        'refunded_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    rpmOrder($business, $main, $user, 'ORD-RPT-2', 'cancelled', '30.0000', now()->toDateTimeString());

    $foreignOrder = rpmOrder($business, $other, $user, 'ORD-RPT-3', 'paid', '999.0000');
    DB::table('payments')->insert([
        'id' => (string) Str::ulid(),
        'business_id' => $business->id,
        'order_id' => $foreignOrder,
        'cash_session_id' => null,
        'collected_by_user_id' => $user->id,
        'method' => 'card',
        'status' => 'completed',
        'amount' => '999.0000',
        'amount_base' => '999.0000',
        'currency' => 'EUR',
        'base_currency' => 'EUR',
        'exchange_rate' => '1.0000000000',
        'idempotency_key' => 'foreign-payment',
        'paid_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->getJson(
        "/api/v1/reports/operational?location_id={$main->id}&from={$date}&to={$date}",
        $headers,
    )->assertOk()
        ->assertJsonPath('data.scope.location_id', $main->id)
        ->assertJsonPath('data.summary.gross_sales', '100.0000')
        ->assertJsonPath('data.summary.refunds', '20.0000')
        ->assertJsonPath('data.summary.net_sales', '80.0000')
        ->assertJsonPath('data.summary.paid_order_count', 1)
        ->assertJsonPath('data.summary.average_ticket', '80.0000')
        ->assertJsonPath('data.summary.orders_opened', 2)
        ->assertJsonPath('data.summary.cancelled_orders', 1)
        ->assertJsonPath('data.summary.discounts', '10.0000')
        ->assertJsonPath('data.summary.voided_items', 1)
        ->assertJsonPath('data.summary.voided_value', '20.0000');

    $data = $response->json('data');

    expect($data['payment_mix'])->toHaveCount(1)
        ->and($data['payment_mix'][0]['method'])->toBe('cash')
        ->and($data['payment_mix'][0]['gross_amount'])->toBe('100.0000')
        ->and($data['payment_mix'][0]['refund_amount'])->toBe('20.0000')
        ->and($data['payment_mix'][0]['net_amount'])->toBe('80.0000')
        ->and($data['product_mix'])->toHaveCount(1)
        ->and($data['product_mix'][0]['product_name'])->toBe('Espresso')
        ->and($data['product_mix'][0]['quantity'])->toBe('2.0000')
        ->and($data['staff_activity'][0]['orders_opened'])->toBe(2)
        ->and($data['staff_activity'][0]['cancelled_orders'])->toBe(1);
});

test('financial report nets reversals and keeps unallocated expenses separate', function (): void {
    $business = rpmBusiness('Financial Reports');
    $location = rpmLocation($business, 'Main');
    $user = rpmUser($business, ['reports.financial.view'], 'Finance User');
    $headers = rpmHeaders($user, $business);
    $date = now($business->timezone)->toDateString();

    $order = rpmOrder($business, $location, $user, 'ORD-FIN-1', 'partially_refunded', '100.0000');
    $payment = (string) Str::ulid();

    DB::table('payments')->insert([
        'id' => $payment,
        'business_id' => $business->id,
        'order_id' => $order,
        'cash_session_id' => null,
        'collected_by_user_id' => $user->id,
        'method' => 'card',
        'status' => 'completed',
        'amount' => '100.0000',
        'amount_base' => '100.0000',
        'currency' => 'EUR',
        'base_currency' => 'EUR',
        'exchange_rate' => '1.0000000000',
        'idempotency_key' => 'fin-payment',
        'paid_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

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
        'idempotency_key' => 'fin-refund',
        'status' => 'completed',
        'refunded_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $expense = (string) Str::ulid();
    DB::table('expenses')->insert([
        'id' => $expense,
        'business_id' => $business->id,
        'location_id' => $location->id,
        'created_by_user_id' => $user->id,
        'category' => 'Utilities',
        'description' => 'Electricity',
        'amount' => '30.0000',
        'currency' => 'EUR',
        'expense_date' => $date,
        'status' => 'posted',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $reversedExpense = (string) Str::ulid();
    DB::table('expenses')->insert([
        'id' => $reversedExpense,
        'business_id' => $business->id,
        'location_id' => $location->id,
        'created_by_user_id' => $user->id,
        'category' => 'Supplies',
        'description' => 'Wrong supplier expense',
        'amount' => '10.0000',
        'currency' => 'EUR',
        'expense_date' => $date,
        'status' => 'reversed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('expenses')->insert([
        'id' => (string) Str::ulid(),
        'business_id' => $business->id,
        'location_id' => $location->id,
        'created_by_user_id' => $user->id,
        'category' => 'Supplies',
        'description' => 'Reversal: Wrong supplier expense',
        'amount' => '10.0000',
        'currency' => 'EUR',
        'expense_date' => $date,
        'status' => 'reversal',
        'reversal_of_expense_id' => $reversedExpense,
        'reversed_by_user_id' => $user->id,
        'reversal_reason' => 'Duplicate',
        'reversed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('expenses')->insert([
        'id' => (string) Str::ulid(),
        'business_id' => $business->id,
        'location_id' => null,
        'created_by_user_id' => $user->id,
        'category' => 'Accounting',
        'description' => 'Business-wide accounting',
        'amount' => '7.0000',
        'currency' => 'EUR',
        'expense_date' => $date,
        'status' => 'posted',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $invoice = (string) Str::ulid();
    DB::table('invoices')->insert([
        'id' => $invoice,
        'business_id' => $business->id,
        'location_id' => $location->id,
        'order_id' => $order,
        'created_by_user_id' => $user->id,
        'number' => 'INV-RPT-1',
        'status' => 'issued',
        'currency' => 'EUR',
        'subtotal' => '100.0000',
        'tax_total' => '0.0000',
        'grand_total' => '100.0000',
        'issued_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('invoice_credit_notes')->insert([
        'id' => (string) Str::ulid(),
        'business_id' => $business->id,
        'invoice_id' => $invoice,
        'created_by_user_id' => $user->id,
        'number' => 'CN-RPT-1',
        'invoice_number_snapshot' => 'INV-RPT-1',
        'status' => 'issued',
        'currency' => 'EUR',
        'subtotal' => '20.0000',
        'discount_total' => '0.0000',
        'tax_total' => '0.0000',
        'grand_total' => '20.0000',
        'reason' => 'Correction',
        'idempotency_key' => 'cn-report-1',
        'issued_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $register = (string) Str::ulid();
    DB::table('cash_registers')->insert([
        'id' => $register,
        'business_id' => $business->id,
        'location_id' => $location->id,
        'name' => 'Main Till',
        'code' => 'RPT',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach (['2.0000', '-1.0000'] as $index => $difference) {
        DB::table('cash_sessions')->insert([
            'id' => (string) Str::ulid(),
            'business_id' => $business->id,
            'location_id' => $location->id,
            'cash_register_id' => $register,
            'opened_by_user_id' => $user->id,
            'closed_by_user_id' => $user->id,
            'base_currency' => 'EUR',
            'opening_cash' => '100.0000',
            'expected_cash' => '100.0000',
            'counted_cash' => $index === 0 ? '102.0000' : '99.0000',
            'cash_difference' => $difference,
            'status' => 'closed',
            'opened_at' => now()->subHour(),
            'closed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $supplier = (string) Str::ulid();
    DB::table('suppliers')->insert([
        'id' => $supplier,
        'business_id' => $business->id,
        'name' => 'Report Supplier',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $product = (string) Str::ulid();
    DB::table('products')->insert([
        'id' => $product,
        'business_id' => $business->id,
        'name' => 'Report Stock',
        'sale_price' => '10.0000',
        'tax_rate' => '0.0000',
        'tracks_stock' => true,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $purchaseOrder = (string) Str::ulid();
    DB::table('purchase_orders')->insert([
        'id' => $purchaseOrder,
        'business_id' => $business->id,
        'location_id' => $location->id,
        'supplier_id' => $supplier,
        'created_by_user_id' => $user->id,
        'supplier_name_snapshot' => 'Report Supplier',
        'number' => 'PO-RPT-1',
        'idempotency_key' => 'report-po-key-0001',
        'request_snapshot' => json_encode([
            'location_id' => $location->id,
            'supplier_id' => $supplier,
            'notes' => null,
            'items' => [[
                'product_id' => $product,
                'quantity_ordered' => '4.0000',
                'unit_cost' => '10.0000',
            ]],
        ], JSON_THROW_ON_ERROR),
        'status' => 'received',
        'currency' => 'EUR',
        'total_cost' => '40.0000',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $purchaseItem = (string) Str::ulid();
    DB::table('purchase_order_items')->insert([
        'id' => $purchaseItem,
        'business_id' => $business->id,
        'purchase_order_id' => $purchaseOrder,
        'product_id' => $product,
        'product_name_snapshot' => 'Report Stock',
        'quantity_ordered' => '4.0000',
        'quantity_received' => '4.0000',
        'unit_cost' => '10.0000',
        'line_total' => '40.0000',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $receipt = (string) Str::ulid();
    DB::table('goods_receipts')->insert([
        'id' => $receipt,
        'business_id' => $business->id,
        'location_id' => $location->id,
        'purchase_order_id' => $purchaseOrder,
        'received_by_user_id' => $user->id,
        'number' => 'GRN-RPT-1',
        'idempotency_key' => 'report-grn-key-0001',
        'request_snapshot' => json_encode([
            'note' => null,
            'items' => [[
                'purchase_order_item_id' => $purchaseItem,
                'quantity_received' => '4.0000',
            ]],
        ], JSON_THROW_ON_ERROR),
        'received_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('goods_receipt_items')->insert([
        'id' => (string) Str::ulid(),
        'business_id' => $business->id,
        'goods_receipt_id' => $receipt,
        'purchase_order_item_id' => $purchaseItem,
        'product_id' => $product,
        'quantity_received' => '4.0000',
        'unit_cost_snapshot' => '10.0000',
        'line_total' => '40.0000',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->getJson(
        "/api/v1/reports/financial?location_id={$location->id}&from={$date}&to={$date}",
        $headers,
    )->assertOk()
        ->assertJsonPath('data.summary.gross_sales', '100.0000')
        ->assertJsonPath('data.summary.refunds', '20.0000')
        ->assertJsonPath('data.summary.net_sales', '80.0000')
        ->assertJsonPath('data.summary.location_expenses', '30.0000')
        ->assertJsonPath('data.summary.unallocated_business_expenses', '7.0000')
        ->assertJsonPath('data.summary.net_after_location_expenses', '50.0000')
        ->assertJsonPath('data.summary.invoice_count', 1)
        ->assertJsonPath('data.summary.invoice_total', '100.0000')
        ->assertJsonPath('data.summary.credit_note_count', 1)
        ->assertJsonPath('data.summary.credit_note_total', '20.0000')
        ->assertJsonPath('data.summary.goods_receipt_count', 1)
        ->assertJsonPath('data.summary.goods_received_cost', '40.0000')
        ->assertJsonPath('data.cash_reconciliation.closed_sessions', 2)
        ->assertJsonPath('data.cash_reconciliation.cash_over', '2.0000')
        ->assertJsonPath('data.cash_reconciliation.cash_short', '1.0000')
        ->assertJsonPath('data.cash_reconciliation.net_variance', '1.0000');

    $categories = collect($response->json('data.expense_categories'))->keyBy('category');

    expect($categories['Utilities']['net_amount'])->toBe('30.0000')
        ->and($categories['Supplies']['net_amount'])->toBe('0.0000');
});

test('report permissions date limits and tenant location boundaries are enforced', function (): void {
    $business = rpmBusiness('Report RBAC');
    $location = rpmLocation($business, 'Main');
    $otherBusiness = rpmBusiness('Other Report Tenant');
    $foreignLocation = rpmLocation($otherBusiness, 'Foreign');
    $date = now($business->timezone)->toDateString();

    $operationalUser = rpmUser($business, ['reports.operational.view']);
    $operationalHeaders = rpmHeaders($operationalUser, $business);

    $this->getJson(
        "/api/v1/reports/operational?location_id={$location->id}&from={$date}&to={$date}",
        $operationalHeaders,
    )->assertOk();

    $this->getJson(
        "/api/v1/reports/financial?location_id={$location->id}&from={$date}&to={$date}",
        $operationalHeaders,
    )->assertForbidden();

    $financialUser = rpmUser($business, ['reports.financial.view']);
    $financialHeaders = rpmHeaders($financialUser, $business);

    $this->getJson(
        "/api/v1/reports/financial?location_id={$location->id}&from={$date}&to={$date}",
        $financialHeaders,
    )->assertOk();

    $this->getJson(
        "/api/v1/reports/operational?location_id={$location->id}&from={$date}&to={$date}",
        $financialHeaders,
    )->assertForbidden();

    $this->getJson(
        "/api/v1/reports/financial?location_id={$foreignLocation->id}&from={$date}&to={$date}",
        $financialHeaders,
    )->assertStatus(422)->assertJsonValidationErrors('location_id');

    $this->getJson(
        "/api/v1/reports/financial?location_id={$location->id}&from=2025-01-01&to=2026-12-31",
        $financialHeaders,
    )->assertStatus(422)->assertJsonValidationErrors('to');

    $this->getJson(
        "/api/v1/reports/financial?location_id={$location->id}&from=2026-10-01&to=2026-09-01",
        $financialHeaders,
    )->assertStatus(422)->assertJsonValidationErrors('to');
});
