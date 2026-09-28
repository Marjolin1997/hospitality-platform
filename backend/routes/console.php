<?php

use App\Jobs\FiscalizeInvoiceJob;
use App\Jobs\FiscalizeCreditNoteJob;
use Database\Seeders\DemoWorkspaceSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;

Schedule::call(function (): void {
    $latest = DB::table('invoice_fiscalization_attempts')
        ->select('invoice_id', DB::raw('MAX(attempt_no) as max_attempt'))
        ->groupBy('invoice_id');

    DB::table('invoice_fiscalization_attempts as a')
        ->joinSub($latest, 'latest', function ($join): void {
            $join->on('latest.invoice_id', '=', 'a.invoice_id')
                ->on('latest.max_attempt', '=', 'a.attempt_no');
        })
        ->where('a.status', 'retry_pending')
        ->where('a.retryable', true)
        ->whereNotNull('a.next_retry_at')
        ->where('a.next_retry_at', '<=', now())
        ->orderBy('a.next_retry_at')
        ->limit(100)
        ->get(['a.business_id','a.invoice_id'])
        ->each(fn ($row) => FiscalizeInvoiceJob::dispatch(
            (string) $row->business_id,
            (string) $row->invoice_id,
            true,
        ));
})->name('fiscalization-retry-dispatch')->everyMinute()->withoutOverlapping();

Schedule::call(function (): void {
    $latest = DB::table('credit_note_fiscalization_attempts')
        ->select('invoice_credit_note_id', DB::raw('MAX(attempt_no) as max_attempt'))
        ->groupBy('invoice_credit_note_id');

    DB::table('credit_note_fiscalization_attempts as a')
        ->joinSub($latest, 'latest', function ($join): void {
            $join->on('latest.invoice_credit_note_id', '=', 'a.invoice_credit_note_id')
                ->on('latest.max_attempt', '=', 'a.attempt_no');
        })
        ->where('a.status', 'retry_pending')
        ->where('a.retryable', true)
        ->whereNotNull('a.next_retry_at')
        ->where('a.next_retry_at', '<=', now())
        ->orderBy('a.next_retry_at')
        ->limit(100)
        ->get(['a.business_id','a.invoice_credit_note_id'])
        ->each(fn ($row) => FiscalizeCreditNoteJob::dispatch(
            (string) $row->business_id,
            (string) $row->invoice_credit_note_id,
            true,
        ));
})->name('credit-note-fiscalization-retry-dispatch')->everyMinute()->withoutOverlapping();

Artisan::command('demo:prepare', function (): int {
    if (! app()->environment(['local', 'testing'])) {
        $this->error('demo:prepare is restricted to local/testing environments.');
        return 1;
    }

    $this->info('Preparing complete local demo workspace…');

    $migrateExit = $this->call('migrate', ['--force' => true]);
    if ($migrateExit !== 0) {
        $this->error('Migration failed. Demo data was not seeded.');
        return $migrateExit;
    }

    $seedExit = $this->call('db:seed', ['--class' => DemoWorkspaceSeeder::class]);
    if ($seedExit !== 0) {
        $this->error('Demo seeding failed.');
        return $seedExit;
    }

    $this->call('optimize:clear');

    if (! is_link(public_path('storage')) && ! file_exists(public_path('storage'))) {
        $this->call('storage:link');
    }

    $this->newLine();
    return $this->call('demo:check');
})->purpose('Migrate, seed and verify the complete local hospitality demo workspace');

Artisan::command('demo:seed', function (): int {
    if (! app()->environment(['local', 'testing'])) {
        $this->error('demo:seed is restricted to local/testing environments.');
        return 1;
    }

    $this->call('db:seed', [
        '--class' => DemoWorkspaceSeeder::class,
    ]);

    $this->newLine();
    $this->info('Next: php artisan demo:check');

    return 0;
})->purpose('Seed the complete local hospitality demo workspace');

Artisan::command('demo:check', function (): int {
    $this->info('Hospitality demo workspace diagnostics');
    $this->newLine();

    $migrationFiles = collect(File::files(database_path('migrations')))
        ->map(fn ($file) => $file->getFilenameWithoutExtension())
        ->sort()
        ->values();

    $ranMigrations = Schema::hasTable('migrations')
        ? DB::table('migrations')->pluck('migration')
        : collect();

    $pending = $migrationFiles->diff($ranMigrations)->values();

    if ($pending->isNotEmpty()) {
        $this->error('Pending migrations detected:');
        foreach ($pending as $migration) {
            $this->line('  - '.$migration);
        }
        $this->newLine();
        $this->warn('Run: php artisan migrate');
    } else {
        $this->info('Migrations: OK');
    }

    $user = DB::table('users')->where('email', DemoWorkspaceSeeder::OWNER_EMAIL)->first();
    $business = Schema::hasTable('businesses')
        ? DB::table('businesses')->where('tax_number', DemoWorkspaceSeeder::BUSINESS_TAX_NUMBER)->first()
        : null;

    if (! $user || ! $business) {
        $this->error('Demo workspace is not seeded.');
        $this->line('Run: php artisan demo:seed');
        return 1;
    }

    $membership = DB::table('business_user')
        ->where('business_id', $business->id)
        ->where('user_id', $user->id)
        ->first();

    $permissionTotal = DB::table('permissions')->count();
    $grantedPermissions = $membership?->role_id
        ? DB::table('permission_role')->where('role_id', $membership->role_id)->count()
        : 0;

    $ownerOk = $membership
        && $membership->status === 'active'
        && $permissionTotal > 0
        && $grantedPermissions === $permissionTotal;

    $this->newLine();
    $this->line('Demo login');
    $this->line('  Email: '.DemoWorkspaceSeeder::OWNER_EMAIL);
    $this->line('  Password: '.DemoWorkspaceSeeder::OWNER_PASSWORD);
    $this->line('  Business: '.$business->name);
    $this->line('  Full permissions: '.($ownerOk ? 'YES' : 'NO'));
    $this->line('  Demo invite: http://localhost:8080/join/'.DemoWorkspaceSeeder::INVITATION_TOKEN);
    $this->newLine();

    $redisRows = [];
    $redisOk = true;
    $redisConnections = [
        'Queue' => (string) (config('queue.connections.redis.connection') ?: 'default'),
        'Cache' => (string) (config('cache.stores.redis.connection') ?: 'cache'),
        'Session' => (string) (config('session.connection') ?: 'default'),
    ];

    foreach ($redisConnections as $purpose => $connection) {
        try {
            $database = config("database.redis.{$connection}.database");
            $size = (int) Redis::connection($connection)->command('dbsize');
            $ping = Redis::connection($connection)->command('ping');
            $healthy = is_string($ping)
                ? strtoupper($ping) === 'PONG'
                : (string) $ping === '1';

            $redisRows[] = [
                $purpose,
                $connection,
                $database === null ? '—' : (string) $database,
                (string) $size,
                $healthy ? 'OK' : 'CHECK',
            ];

            if (! $healthy) {
                $redisOk = false;
            }
        } catch (Throwable $error) {
            $redisRows[] = [$purpose, $connection, '—', 'ERROR', 'CHECK'];
            $redisOk = false;
        }
    }

    $this->line('Redis runtime');
    $this->table(['Purpose', 'Connection', 'DB', 'Keys', 'Status'], $redisRows);
    $this->line('  Note: 0 keys is valid for an idle cache/session database; it is not a Redis failure.');
    $this->newLine();

    $routeUris = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route) => $route->uri())
        ->unique()
        ->values();

    $modules = [
        'Dashboard' => [
            'routes' => ['api/v1/orders', 'api/v1/inventory', 'api/v1/bar-queue', 'api/v1/venue', 'api/v1/finance/overview'],
            'data' => fn () => DB::table('orders')->where('business_id', $business->id)->count(),
            'minimum' => 1,
        ],
        'POS' => [
            'routes' => ['api/v1/catalog', 'api/v1/venue', 'api/v1/orders'],
            'data' => fn () => DB::table('products')->where('business_id', $business->id)->where('is_active', true)->count(),
            'minimum' => 1,
        ],
        'Cash Register' => [
            'routes' => ['api/v1/cash-registers', 'api/v1/cash-sessions/open'],
            'data' => fn () => DB::table('cash_registers')->where('business_id', $business->id)->count(),
            'minimum' => 1,
        ],
        'Bar Queue' => [
            'routes' => ['api/v1/bar-queue'],
            'data' => fn () => DB::table('order_items')->where('business_id', $business->id)->whereIn('preparation_status', ['sent', 'preparing', 'ready'])->count(),
            'minimum' => 1,
        ],
        'Menu & Products' => [
            'routes' => ['api/v1/management/products', 'api/v1/management/categories', 'api/v1/preparation-stations'],
            'data' => fn () => DB::table('products')->where('business_id', $business->id)->count(),
            'minimum' => 1,
        ],
        'Inventory' => [
            'routes' => ['api/v1/inventory', 'api/v1/inventory/movements', 'api/v1/inventory/transfers', 'api/v1/inventory/counts'],
            'data' => fn () => DB::table('inventory_stocks')->where('business_id', $business->id)->count(),
            'minimum' => 1,
        ],
        'Purchasing' => [
            'routes' => ['api/v1/purchasing/suppliers', 'api/v1/purchase-orders'],
            'data' => fn () => DB::table('purchase_orders')->where('business_id', $business->id)->count(),
            'minimum' => 1,
        ],
        'Finance' => [
            'routes' => ['api/v1/finance/overview', 'api/v1/expenses'],
            'data' => fn () => DB::table('expenses')->where('business_id', $business->id)->count(),
            'minimum' => 1,
        ],
        'Reports' => [
            'routes' => ['api/v1/reports/locations', 'api/v1/reports/operational', 'api/v1/reports/financial'],
            'data' => fn () => DB::table('payments')->where('business_id', $business->id)->where('status', 'completed')->count(),
            'minimum' => 1,
        ],
        'Invoices' => [
            'routes' => ['api/v1/invoices'],
            'data' => fn () => DB::table('invoices')->where('business_id', $business->id)->count(),
            'minimum' => 4,
        ],
        'Venue Setup' => [
            'routes' => ['api/v1/management/venue', 'api/v1/management/cash-registers'],
            'data' => fn () => DB::table('venue_tables')->where('business_id', $business->id)->count(),
            'minimum' => 1,
        ],
        'Staff' => [
            'routes' => ['api/v1/staff', 'api/v1/roles', 'api/v1/staff-invitations'],
            'data' => fn () => DB::table('business_user')->where('business_id', $business->id)->count(),
            'minimum' => 2,
        ],
        'Settings' => [
            'routes' => ['api/v1/settings', 'api/v1/management/locations', 'api/v1/fiscalization/setup'],
            'data' => fn () => DB::table('locations')->where('business_id', $business->id)->count(),
            'minimum' => 1,
        ],
    ];

    $rows = [];
    $allOk = $pending->isEmpty() && $ownerOk && $redisOk;

    foreach ($modules as $module => $definition) {
        $missingRoutes = collect($definition['routes'])
            ->reject(fn (string $uri): bool => $routeUris->contains($uri))
            ->values();

        try {
            $dataCount = (int) $definition['data']();
        } catch (Throwable $error) {
            $dataCount = -1;
        }

        $routesOk = $missingRoutes->isEmpty();
        $dataOk = $dataCount >= $definition['minimum'];
        $status = $routesOk && $dataOk ? 'OK' : 'CHECK';

        if ($status !== 'OK') {
            $allOk = false;
        }

        $rows[] = [
            $module,
            $routesOk ? 'OK' : 'Missing: '.$missingRoutes->join(', '),
            $dataCount >= 0 ? (string) $dataCount : 'ERROR',
            $status,
        ];
    }

    $this->table(['Menu', 'API routes', 'Demo rows', 'Status'], $rows);

    $invoiceStates = DB::table('invoices')
        ->where('business_id', $business->id)
        ->pluck('fiscalization_status')
        ->all();
    $qrInvoices = DB::table('invoices')
        ->where('business_id', $business->id)
        ->whereNotNull('qr_payload')
        ->count();
    $creditNotes = DB::table('invoice_credit_notes')
        ->where('business_id', $business->id)
        ->count();
    $pendingInvites = DB::table('staff_invitations')
        ->where('business_id', $business->id)
        ->where('status', 'pending')
        ->count();
    $fiscalProfile = DB::table('fiscalization_profiles')
        ->where('business_id', $business->id)
        ->first();
    $fiscalLocations = DB::table('locations')
        ->where('business_id', $business->id)
        ->where('is_active', true)
        ->whereNotNull('fiscal_business_unit_code')
        ->count();
    $fiscalRegisters = DB::table('cash_registers')
        ->where('business_id', $business->id)
        ->where('is_active', true)
        ->whereNotNull('fiscal_tcr_code')
        ->count();
    $fiscalOperators = DB::table('business_user')
        ->where('business_id', $business->id)
        ->where('status', 'active')
        ->whereNotNull('fiscal_operator_code')
        ->count();

    $expectedStates = ['fiscalized', 'not_fiscalized', 'failed'];
    $stateCoverage = collect($expectedStates)->every(fn (string $state): bool => in_array($state, $invoiceStates, true));
    $requestedInvoice = DB::table('invoices')
        ->where('business_id', $business->id)
        ->where('number', 'INV-20260928-0001')
        ->first();

    $requestedInvoiceOk = $requestedInvoice !== null
        && $requestedInvoice->status === 'issued'
        && $requestedInvoice->fiscalization_status === 'fiscalized'
        && (string) $requestedInvoice->grand_total === '25.0000'
        && is_string($requestedInvoice->qr_payload)
        && str_starts_with($requestedInvoice->qr_payload, 'DEMO|LOCAL_ONLY');

    $invoiceDemoOk = $stateCoverage && $qrInvoices >= 2 && $creditNotes >= 1 && $requestedInvoiceOk;
    $staffDemoOk = DB::table('business_user')->where('business_id', $business->id)->count() >= 7 && $pendingInvites >= 1;
    $fiscalDemoOk = $fiscalProfile !== null && $fiscalLocations >= 2 && $fiscalRegisters >= 2 && $fiscalOperators >= 3;

    $this->newLine();
    $this->table(
        ['Demo scenario', 'Status', 'Details'],
        [
            [
                'Invoice filters + QR + correction',
                $invoiceDemoOk ? 'OK' : 'CHECK',
                'states='.implode(',', array_values(array_unique($invoiceStates))).'; qr='.$qrInvoices.'; credit_notes='.$creditNotes.'; requested_invoice='.($requestedInvoiceOk ? 'OK' : 'CHECK'),
            ],
            [
                'Staff lifecycle',
                $staffDemoOk ? 'OK' : 'CHECK',
                'members='.DB::table('business_user')->where('business_id', $business->id)->count().'; pending_invites='.$pendingInvites,
            ],
            [
                'Fiscal identity',
                $fiscalDemoOk ? 'OK' : 'CHECK',
                'locations='.$fiscalLocations.'; TCR='.$fiscalRegisters.'; operators='.$fiscalOperators.'; profile='.($fiscalProfile?->status ?? 'missing'),
            ],
        ],
    );

    $allOk = $allOk && $invoiceDemoOk && $staffDemoOk && $fiscalDemoOk;

    $this->newLine();
    $demoInvoice = DB::table('invoices')
        ->where('business_id', $business->id)
        ->where('number', 'DEMO-INV-001')
        ->first(['id', 'number']);

    $requestedQrInvoice = DB::table('invoices')
        ->where('business_id', $business->id)
        ->where('number', 'INV-20260928-0001')
        ->first(['id', 'number', 'status', 'grand_total', 'fiscalization_status']);

    $demoCreditNote = DB::table('invoice_credit_notes')
        ->where('business_id', $business->id)
        ->where('number', 'DEMO-CN-001')
        ->first(['id', 'number']);

    $this->line('Open these pages after login:');
    foreach ([
        '/dashboard',
        '/pos',
        '/cash-register',
        '/bar',
        '/products',
        '/inventory',
        '/purchasing',
        '/finance',
        '/reports',
        '/invoices',
        '/venue-setup',
        '/staff',
        '/settings#fiscal-identity',
    ] as $path) {
        $this->line('  http://localhost:8080'.$path);
    }

    if ($requestedQrInvoice) {
        $this->newLine();
        $this->line('Requested fiscal QR invoice:');
        $this->line('  ID: '.$requestedQrInvoice->id);
        $this->line('  Number: '.$requestedQrInvoice->number);
        $this->line('  Status: '.$requestedQrInvoice->status.' / '.$requestedQrInvoice->fiscalization_status);
        $this->line('  Total: '.$requestedQrInvoice->grand_total);
        $this->line('  Invoice + QR: http://localhost:8080/invoices/'.$requestedQrInvoice->id.'/print');
        $this->line('  Receipt 80mm + QR: http://localhost:8080/invoices/'.$requestedQrInvoice->id.'/receipt/80');
        $this->line('  Receipt 58mm + QR: http://localhost:8080/invoices/'.$requestedQrInvoice->id.'/receipt/58');
    }

    if ($demoInvoice) {
        $this->newLine();
        $this->line('Other demo fiscal document URLs:');
        $this->line('  Invoice + QR: http://localhost:8080/invoices/'.$demoInvoice->id.'/print');
        $this->line('  Receipt 80mm: http://localhost:8080/invoices/'.$demoInvoice->id.'/receipt/80');
        $this->line('  Receipt 58mm: http://localhost:8080/invoices/'.$demoInvoice->id.'/receipt/58');
    }

    if ($demoCreditNote) {
        $this->line('  Credit note + QR: http://localhost:8080/invoice-credit-notes/'.$demoCreditNote->id.'/print');
        $this->line('  Credit receipt 80mm: http://localhost:8080/invoice-credit-notes/'.$demoCreditNote->id.'/receipt/80');
    }

    $this->newLine();
    if ($allOk) {
        $this->info('Demo menu prerequisites: GREEN');
        return 0;
    }

    $this->warn('One or more menu prerequisites need attention.');
    return 1;
})->purpose('Check migrations, demo data, permissions, routes and menu prerequisites');

