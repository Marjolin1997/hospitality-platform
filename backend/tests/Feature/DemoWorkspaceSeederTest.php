<?php

use Database\Seeders\DemoWorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('demo workspace seeder is idempotent and covers every operational menu scenario', function (): void {
    $this->seed(DemoWorkspaceSeeder::class);

    $business = DB::table('businesses')
        ->where('tax_number', DemoWorkspaceSeeder::BUSINESS_TAX_NUMBER)
        ->firstOrFail();

    $owner = DB::table('users')
        ->where('email', DemoWorkspaceSeeder::OWNER_EMAIL)
        ->firstOrFail();

    $membership = DB::table('business_user')
        ->where('business_id', $business->id)
        ->where('user_id', $owner->id)
        ->firstOrFail();

    $permissionCount = DB::table('permissions')->count();
    $ownerPermissionCount = DB::table('permission_role')
        ->where('role_id', $membership->role_id)
        ->count();

    expect($business->name)->toBe(DemoWorkspaceSeeder::BUSINESS_NAME)
        ->and($membership->status)->toBe('active')
        ->and($ownerPermissionCount)->toBe($permissionCount)
        ->and(DB::table('locations')->where('business_id', $business->id)->count())->toBeGreaterThanOrEqual(3)
        ->and(DB::table('products')->where('business_id', $business->id)->count())->toBeGreaterThanOrEqual(6)
        ->and(DB::table('venue_tables')->where('business_id', $business->id)->count())->toBeGreaterThanOrEqual(6)
        ->and(DB::table('inventory_stocks')->where('business_id', $business->id)->count())->toBeGreaterThanOrEqual(8)
        ->and(DB::table('purchase_orders')->where('business_id', $business->id)->count())->toBeGreaterThanOrEqual(2)
        ->and(DB::table('expenses')->where('business_id', $business->id)->count())->toBeGreaterThanOrEqual(2)
        ->and(DB::table('business_user')->where('business_id', $business->id)->count())->toBeGreaterThanOrEqual(7)
        ->and(DB::table('staff_invitations')->where('business_id', $business->id)->where('status', 'pending')->count())->toBeGreaterThanOrEqual(1);

    $invoiceStates = DB::table('invoices')
        ->where('business_id', $business->id)
        ->pluck('fiscalization_status')
        ->all();

    expect($invoiceStates)
        ->toContain('fiscalized', 'not_fiscalized', 'failed')
        ->and(DB::table('invoices')
            ->where('business_id', $business->id)
            ->whereNotNull('qr_payload')
            ->where('qr_payload', 'like', 'DEMO|LOCAL_ONLY|%')
            ->count())->toBeGreaterThanOrEqual(1)
        ->and(DB::table('invoice_credit_notes')
            ->where('business_id', $business->id)
            ->whereNotNull('qr_payload')
            ->where('qr_payload', 'like', 'DEMO|LOCAL_ONLY|%')
            ->count())->toBeGreaterThanOrEqual(1)
        ->and(DB::table('invoice_fiscalization_attempts')
            ->where('business_id', $business->id)
            ->whereIn('status', ['succeeded', 'failed'])
            ->count())->toBeGreaterThanOrEqual(2);

    $profile = DB::table('fiscalization_profiles')
        ->where('business_id', $business->id)
        ->firstOrFail();

    expect($profile->environment)->toBe('test')
        ->and($profile->status)->toBe('configured')
        ->and(DB::table('locations')
            ->where('business_id', $business->id)
            ->where('is_active', true)
            ->whereNotNull('fiscal_business_unit_code')
            ->count())->toBeGreaterThanOrEqual(2)
        ->and(DB::table('cash_registers')
            ->where('business_id', $business->id)
            ->where('is_active', true)
            ->whereNotNull('fiscal_tcr_code')
            ->count())->toBeGreaterThanOrEqual(2)
        ->and(DB::table('business_user')
            ->where('business_id', $business->id)
            ->where('status', 'active')
            ->whereNotNull('fiscal_operator_code')
            ->count())->toBeGreaterThanOrEqual(3);

    $countsBefore = [
        'businesses' => DB::table('businesses')->where('id', $business->id)->count(),
        'members' => DB::table('business_user')->where('business_id', $business->id)->count(),
        'products' => DB::table('products')->where('business_id', $business->id)->count(),
        'invoices' => DB::table('invoices')->where('business_id', $business->id)->count(),
        'credit_notes' => DB::table('invoice_credit_notes')->where('business_id', $business->id)->count(),
        'purchase_orders' => DB::table('purchase_orders')->where('business_id', $business->id)->count(),
        'staff_invitations' => DB::table('staff_invitations')->where('business_id', $business->id)->count(),
    ];

    $this->seed(DemoWorkspaceSeeder::class);

    $countsAfter = [
        'businesses' => DB::table('businesses')->where('id', $business->id)->count(),
        'members' => DB::table('business_user')->where('business_id', $business->id)->count(),
        'products' => DB::table('products')->where('business_id', $business->id)->count(),
        'invoices' => DB::table('invoices')->where('business_id', $business->id)->count(),
        'credit_notes' => DB::table('invoice_credit_notes')->where('business_id', $business->id)->count(),
        'purchase_orders' => DB::table('purchase_orders')->where('business_id', $business->id)->count(),
        'staff_invitations' => DB::table('staff_invitations')->where('business_id', $business->id)->count(),
    ];

    expect($countsAfter)->toBe($countsBefore);
});
