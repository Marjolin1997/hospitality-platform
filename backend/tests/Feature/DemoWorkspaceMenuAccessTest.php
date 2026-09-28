<?php

use App\Models\User;
use Database\Seeders\DemoWorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('demo owner can load the base API contract behind every visible workspace module', function (): void {
    $this->seed(DemoWorkspaceSeeder::class);

    $business = DB::table('businesses')
        ->where('tax_number', DemoWorkspaceSeeder::BUSINESS_TAX_NUMBER)
        ->firstOrFail();

    $location = DB::table('locations')
        ->where('business_id', $business->id)
        ->where('code', 'DEMO-MAIN')
        ->where('is_active', true)
        ->firstOrFail();

    $owner = User::query()
        ->where('email', DemoWorkspaceSeeder::OWNER_EMAIL)
        ->firstOrFail();

    Sanctum::actingAs($owner);
    $headers = ['X-Business-Id' => $business->id];

    $from = now()->subDays(30)->toDateString();
    $to = now()->toDateString();

    $moduleRequests = [
        'Dashboard · orders' => "/api/v1/orders?per_page=100",
        'Dashboard · inventory' => "/api/v1/inventory?location_id={$location->id}",
        'Dashboard · preparation' => "/api/v1/bar-queue?location_id={$location->id}",
        'Dashboard · venue' => "/api/v1/venue?location_id={$location->id}",
        'Dashboard · finance' => '/api/v1/finance/overview',

        'POS · catalog' => '/api/v1/catalog',
        'POS · venue' => "/api/v1/venue?location_id={$location->id}",
        'POS · orders' => "/api/v1/orders?location_id={$location->id}",

        'Cash Register · registers' => "/api/v1/cash-registers?location_id={$location->id}",
        'Cash Register · sessions' => "/api/v1/cash-sessions/open?location_id={$location->id}",

        'Bar Queue' => "/api/v1/bar-queue?location_id={$location->id}",

        'Menu · products' => '/api/v1/management/products',
        'Menu · categories' => '/api/v1/management/categories',
        'Menu · stations' => '/api/v1/preparation-stations',

        'Inventory · stock' => "/api/v1/inventory?location_id={$location->id}",
        'Inventory · movements' => "/api/v1/inventory/movements?location_id={$location->id}",
        'Inventory · transfers' => "/api/v1/inventory/transfers?location_id={$location->id}",
        'Inventory · counts' => "/api/v1/inventory/counts?location_id={$location->id}",

        'Purchasing · suppliers' => '/api/v1/purchasing/suppliers',
        'Purchasing · options' => "/api/v1/purchasing/options?location_id={$location->id}",
        'Purchasing · orders' => "/api/v1/purchase-orders?location_id={$location->id}",

        'Finance · overview' => '/api/v1/finance/overview',
        'Finance · expenses' => '/api/v1/expenses',

        'Reports · locations' => '/api/v1/reports/locations',
        'Reports · operational' => "/api/v1/reports/operational?location_id={$location->id}&from={$from}&to={$to}",
        'Reports · financial' => "/api/v1/reports/financial?location_id={$location->id}&from={$from}&to={$to}",

        'Invoices · list' => '/api/v1/invoices',
        'Invoices · eligible orders' => '/api/v1/invoices/eligible-orders',

        'Venue Setup · venue' => "/api/v1/management/venue?location_id={$location->id}",
        'Venue Setup · registers' => "/api/v1/management/cash-registers?location_id={$location->id}",

        'Staff · members' => '/api/v1/staff',
        'Staff · roles' => '/api/v1/roles',
        'Staff · invitations' => '/api/v1/staff-invitations',

        'Settings · business' => '/api/v1/settings',
        'Settings · locations' => '/api/v1/management/locations',
        'Settings · fiscal profile' => '/api/v1/fiscalization/profile',
        'Settings · fiscal setup' => '/api/v1/fiscalization/setup',
        'Settings · monitoring' => '/api/v1/fiscalization/monitoring',
    ];

    foreach ($moduleRequests as $name => $uri) {
        $response = $this->getJson($uri, $headers);

        $this->assertSame(
            200,
            $response->status(),
            "{$name} failed to load. Response: ".$response->getContent(),
        );
    }

    $invoice = DB::table('invoices')
        ->where('business_id', $business->id)
        ->where('number', 'DEMO-INV-001')
        ->firstOrFail();

    $invoiceResponse = $this->getJson("/api/v1/invoices/{$invoice->id}", $headers)
        ->assertOk()
        ->assertJsonPath('data.invoice.number', 'DEMO-INV-001');

    expect($invoiceResponse->json('data.invoice.qr_payload'))
        ->toStartWith('DEMO|LOCAL_ONLY|');

    $creditNote = DB::table('invoice_credit_notes')
        ->where('business_id', $business->id)
        ->where('number', 'DEMO-CN-001')
        ->firstOrFail();

    $creditResponse = $this->getJson("/api/v1/invoice-credit-notes/{$creditNote->id}", $headers)
        ->assertOk()
        ->assertJsonPath('data.credit_note.number', 'DEMO-CN-001');

    expect($creditResponse->json('data.credit_note.qr_payload'))
        ->toStartWith('DEMO|LOCAL_ONLY|');
});
