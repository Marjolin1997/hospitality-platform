<?php

use Database\Seeders\DemoWorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('demo workspace seeder populates every navigation domain with a full-access owner', function (): void {
    $this->seed(DemoWorkspaceSeeder::class);

    $business = DB::table('businesses')->where('tax_number', 'DEMO-TAX-2026')->firstOrFail();
    $owner = DB::table('users')->where('email', DemoWorkspaceSeeder::OWNER_EMAIL)->firstOrFail();
    $membership = DB::table('business_user')
        ->where('business_id', $business->id)
        ->where('user_id', $owner->id)
        ->firstOrFail();

    expect($membership->status)->toBe('active')
        ->and(DB::table('permission_role')->where('role_id', $membership->role_id)->count())
        ->toBe(DB::table('permissions')->count())
        ->and(DB::table('locations')->where('business_id', $business->id)->count())->toBe(3)
        ->and(DB::table('locations')->where('business_id', $business->id)->where('is_active', true)->count())->toBe(2)
        ->and(DB::table('preparation_stations')->where('business_id', $business->id)->count())->toBeGreaterThanOrEqual(2)
        ->and(DB::table('product_categories')->where('business_id', $business->id)->count())->toBeGreaterThanOrEqual(3)
        ->and(DB::table('products')->where('business_id', $business->id)->count())->toBeGreaterThanOrEqual(6)
        ->and(DB::table('venue_tables')->where('business_id', $business->id)->count())->toBeGreaterThanOrEqual(6)
        ->and(DB::table('cash_registers')->where('business_id', $business->id)->count())->toBeGreaterThanOrEqual(3)
        ->and(DB::table('cash_sessions')->where('business_id', $business->id)->where('status', 'open')->count())->toBe(1)
        ->and(DB::table('cash_sessions')->where('business_id', $business->id)->where('status', 'closed')->count())->toBe(1)
        ->and(DB::table('orders')->where('business_id', $business->id)->count())->toBeGreaterThanOrEqual(5)
        ->and(DB::table('order_items')->where('business_id', $business->id)->whereIn('preparation_status', ['sent', 'preparing', 'ready'])->count())->toBeGreaterThanOrEqual(2)
        ->and(DB::table('payments')->where('business_id', $business->id)->where('status', 'completed')->count())->toBeGreaterThanOrEqual(2)
        ->and(DB::table('payment_refunds')->where('business_id', $business->id)->where('status', 'completed')->count())->toBeGreaterThanOrEqual(1)
        ->and(DB::table('inventory_stocks')->where('business_id', $business->id)->count())->toBeGreaterThanOrEqual(8)
        ->and(DB::table('inventory_movements')->where('business_id', $business->id)->count())->toBeGreaterThanOrEqual(8)
        ->and(DB::table('inventory_transfers')->where('business_id', $business->id)->count())->toBeGreaterThanOrEqual(1)
        ->and(DB::table('inventory_counts')->where('business_id', $business->id)->count())->toBeGreaterThanOrEqual(1)
        ->and(DB::table('suppliers')->where('business_id', $business->id)->count())->toBeGreaterThanOrEqual(2)
        ->and(DB::table('purchase_orders')->where('business_id', $business->id)->count())->toBeGreaterThanOrEqual(2)
        ->and(DB::table('goods_receipts')->where('business_id', $business->id)->count())->toBeGreaterThanOrEqual(1)
        ->and(DB::table('expenses')->where('business_id', $business->id)->count())->toBeGreaterThanOrEqual(2)
        ->and(DB::table('invoices')->where('business_id', $business->id)->count())->toBeGreaterThanOrEqual(1)
        ->and(DB::table('business_user')->where('business_id', $business->id)->count())->toBeGreaterThanOrEqual(7)
        ->and(DB::table('staff_invitations')->where('business_id', $business->id)->count())->toBeGreaterThanOrEqual(1)
        ->and(DB::table('business_settings')->where('business_id', $business->id)->count())->toBeGreaterThanOrEqual(3);

    $invoice = DB::table('invoices')
        ->where('business_id', $business->id)
        ->where('number', 'DEMO-INV-001')
        ->firstOrFail();

    expect((string) $invoice->subtotal)->toBe('16.6667')
        ->and((string) $invoice->discount_total)->toBe('1.9000')
        ->and((string) $invoice->tax_total)->toBe('3.3333')
        ->and((string) $invoice->grand_total)->toBe('20.0000')
        ->and((string) DB::table('invoice_lines')->where('invoice_id', $invoice->id)->sum('line_total'))->toBe('20.0000');
});

test('demo workspace seeder is repeatable without duplicating representative records', function (): void {
    $this->seed(DemoWorkspaceSeeder::class);

    $businessId = DB::table('businesses')->where('tax_number', 'DEMO-TAX-2026')->value('id');

    $before = [
        'businesses' => DB::table('businesses')->where('tax_number', 'DEMO-TAX-2026')->count(),
        'users' => DB::table('users')->where('email', 'like', 'demo.%@hospitality.local')->count(),
        'locations' => DB::table('locations')->where('business_id', $businessId)->count(),
        'products' => DB::table('products')->where('business_id', $businessId)->count(),
        'orders' => DB::table('orders')->where('business_id', $businessId)->count(),
        'payments' => DB::table('payments')->where('business_id', $businessId)->count(),
        'refunds' => DB::table('payment_refunds')->where('business_id', $businessId)->count(),
        'invoices' => DB::table('invoices')->where('business_id', $businessId)->count(),
        'purchase_orders' => DB::table('purchase_orders')->where('business_id', $businessId)->count(),
        'goods_receipts' => DB::table('goods_receipts')->where('business_id', $businessId)->count(),
        'transfers' => DB::table('inventory_transfers')->where('business_id', $businessId)->count(),
        'counts' => DB::table('inventory_counts')->where('business_id', $businessId)->count(),
        'invitations' => DB::table('staff_invitations')->where('business_id', $businessId)->count(),
    ];

    $this->seed(DemoWorkspaceSeeder::class);

    $after = [
        'businesses' => DB::table('businesses')->where('tax_number', 'DEMO-TAX-2026')->count(),
        'users' => DB::table('users')->where('email', 'like', 'demo.%@hospitality.local')->count(),
        'locations' => DB::table('locations')->where('business_id', $businessId)->count(),
        'products' => DB::table('products')->where('business_id', $businessId)->count(),
        'orders' => DB::table('orders')->where('business_id', $businessId)->count(),
        'payments' => DB::table('payments')->where('business_id', $businessId)->count(),
        'refunds' => DB::table('payment_refunds')->where('business_id', $businessId)->count(),
        'invoices' => DB::table('invoices')->where('business_id', $businessId)->count(),
        'purchase_orders' => DB::table('purchase_orders')->where('business_id', $businessId)->count(),
        'goods_receipts' => DB::table('goods_receipts')->where('business_id', $businessId)->count(),
        'transfers' => DB::table('inventory_transfers')->where('business_id', $businessId)->count(),
        'counts' => DB::table('inventory_counts')->where('business_id', $businessId)->count(),
        'invitations' => DB::table('staff_invitations')->where('business_id', $businessId)->count(),
    ];

    expect($after)->toBe($before);
});

test('demo check command reports a green workspace after seeding', function (): void {
    $this->seed(DemoWorkspaceSeeder::class);

    $exitCode = Artisan::call('demo:check');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Demo menu prerequisites: GREEN')
        ->and($output)->toContain('Dashboard')
        ->and($output)->toContain('Purchasing')
        ->and($output)->toContain('Reports')
        ->and($output)->toContain('Settings');
});
