<?php

use App\Models\Business;
use App\Models\FiscalizationProfile;
use App\Models\Location;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use App\Services\Fiscalization\ActivateProductionFiscalization;
use App\Services\Fiscalization\FiscalCertificateInspector;
use App\Services\Fiscalization\FiscalizationMonitoring;
use App\Services\Fiscalization\FiscalizationDispatchGuard;
use App\Services\Fiscalization\FiscalizationPreflight;
use App\Services\Fiscalization\SaveFiscalizationProfile;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(PermissionSeeder::class));

function frtCredentials(int $days = 365): array
{
    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);

    $csr = openssl_csr_new([
        'countryName' => 'AL',
        'organizationName' => 'Fiscal Readiness Test',
        'commonName' => 'Fiscal Readiness Certificate',
    ], $key, ['digest_alg' => 'sha256']);

    $certificate = openssl_csr_sign($csr, null, $key, $days, ['digest_alg' => 'sha256']);
    $parsed = openssl_x509_parse($certificate);
    if (! is_array($parsed)) {
        throw new RuntimeException('Readiness test certificate could not be parsed.');
    }

    $pkcs12 = '';
    openssl_pkcs12_export($certificate, $pkcs12, $key, 'readiness-password');

    return [
        'pkcs12' => base64_encode($pkcs12),
        'password' => 'readiness-password',
        'not_before_ts' => (int) $parsed['validFrom_time_t'],
        'not_after_ts' => (int) $parsed['validTo_time_t'],
    ];
}

function frtFixture(string $environment = 'test'): array
{
    $business = Business::query()->create([
        'name' => 'Readiness Business',
        'legal_name' => 'Readiness Business sh.p.k.',
        'tax_number' => 'L12345678A',
        'currency' => 'ALL',
        'timezone' => 'Europe/Tirane',
        'status' => 'active',
    ]);

    $location = Location::query()->create([
        'business_id' => $business->id,
        'name' => 'Tirana Center',
        'code' => 'TIR',
        'type' => 'bar',
        'fiscal_business_unit_code' => 'bb123bb123',
        'is_active' => true,
    ]);

    $user = User::query()->create([
        'name' => 'Fiscal Owner',
        'email' => Str::lower(Str::random(12)).'@example.test',
        'password' => bcrypt('password'),
    ]);

    $role = Role::query()->create([
        'business_id' => $business->id,
        'name' => 'Fiscal Issuer',
        'slug' => 'fiscal-issuer-'.Str::lower(Str::random(6)),
        'is_system' => false,
    ]);
    $role->permissions()->sync(
        Permission::query()->where('key','fiscalization.issue')->pluck('id')
    );

    DB::table('business_user')->insert([
        'business_id' => $business->id,
        'user_id' => $user->id,
        'role_id' => $role->id,
        'status' => 'active',
        'fiscal_operator_code' => 'cc123cc123',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('cash_registers')->insert([
        'id' => (string) Str::ulid(),
        'business_id' => $business->id,
        'location_id' => $location->id,
        'name' => 'Main Register',
        'code' => 'MAIN',
        'fiscal_tcr_code' => 'aa123aa123',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    FiscalizationProfile::query()->create([
        'business_id' => $business->id,
        'provider' => 'direct_dpt',
        'environment' => $environment,
        'status' => 'configured',
        'software_code' => 'dd123dd123',
        'certificate_secret_ref' => 'env:FRT_P12',
        'certificate_password_secret_ref' => 'env:FRT_PASSWORD',
        'is_issuer_in_vat' => true,
        'endpoint' => $environment === 'production'
            ? 'https://prod-dpt.example.test/service'
            : 'https://test-dpt.example.test/service',
    ]);

    return [$business, $user];
}

test('preflight reports a valid certificate and complete TEST fiscal setup without exposing secrets', function (): void {
    $credentials = frtCredentials();
    putenv('FRT_P12='.$credentials['pkcs12']);
    putenv('FRT_PASSWORD='.$credentials['password']);

    try {
        [$business] = frtFixture('test');
        config()->set('fiscalization.test_endpoint', 'https://test-dpt.example.test/service');

        $result = app(FiscalizationPreflight::class)->run($business);

        expect($result['status'])->toBe('ready')
            ->and($result['blocking_failures'])->toBe(0)
            ->and($result['certificate']['valid_now'])->toBeTrue()
            ->and($result['certificate']['private_key_matches'])->toBeTrue()
            ->and(strlen($result['certificate']['fingerprint_sha256']))->toBe(64)
            ->and(json_encode($result))->not->toContain($credentials['password'])
            ->and(json_encode($result))->not->toContain($credentials['pkcs12']);

        $profile = FiscalizationProfile::query()->where('business_id', $business->id)->firstOrFail();
        expect($profile->preflight_status)->toBe('ready')
            ->and($profile->preflight_checked_at)->not->toBeNull()
            ->and($profile->certificate_not_after)->not->toBeNull()
            ->and(strlen((string) $profile->certificate_fingerprint_sha256))->toBe(64);
    } finally {
        putenv('FRT_P12');
        putenv('FRT_PASSWORD');
    }
});

test('expired certificate blocks preflight and runtime certificate inspection', function (): void {
    $credentials = frtCredentials(1);
    putenv('FRT_P12='.$credentials['pkcs12']);
    putenv('FRT_PASSWORD='.$credentials['password']);

    try {
        [$business] = frtFixture('test');
        config()->set('fiscalization.test_endpoint', 'https://test-dpt.example.test/service');

        CarbonImmutable::setTestNow(
            CarbonImmutable::createFromTimestampUTC($credentials['not_after_ts'])->addMinute()
        );

        $certificate = app(FiscalCertificateInspector::class)->inspect('env:FRT_P12', 'env:FRT_PASSWORD');
        $result = app(FiscalizationPreflight::class)->run($business);

        expect($certificate['valid_now'])->toBeFalse()
            ->and($result['status'])->toBe('blocked')
            ->and(collect($result['checks'])->firstWhere('key','certificate_validity')['status'])->toBe('fail');
    } finally {
        CarbonImmutable::setTestNow();
        putenv('FRT_P12');
        putenv('FRT_PASSWORD');
    }
});

test('production activation requires TEST verification approved endpoint CA bundle and fresh preflight', function (): void {
    $credentials = frtCredentials();
    putenv('FRT_P12='.$credentials['pkcs12']);
    putenv('FRT_PASSWORD='.$credentials['password']);
    $ca = tempnam(sys_get_temp_dir(), 'fiscal-ca-');
    file_put_contents($ca, "test-ca-bundle");

    try {
        [$business, $user] = frtFixture('production');
        config()->set('fiscalization.production_endpoint', 'https://prod-dpt.example.test/service');
        config()->set('fiscalization.dpt_ca_bundle', $ca);
        config()->set('queue.default', 'redis');

        $blocked = app(FiscalizationPreflight::class)->run($business);
        expect($blocked['status'])->toBe('blocked')
            ->and(collect($blocked['checks'])->firstWhere('key','test_verified')['status'])->toBe('fail');

        expect(fn () => app(ActivateProductionFiscalization::class)->execute($business, $user))
            ->toThrow(ValidationException::class);

        FiscalizationProfile::query()->where('business_id', $business->id)->update([
            'last_test_verified_at' => now(),
            'last_verified_at' => now(),
        ]);

        $profile = app(ActivateProductionFiscalization::class)->execute($business, $user);

        expect($profile->status)->toBe('active')
            ->and($profile->production_activated_at)->not->toBeNull()
            ->and((int) $profile->production_activated_by_user_id)->toBe($user->id)
            ->and($profile->preflight_status)->toBe('ready');
    } finally {
        @unlink($ca);
        putenv('FRT_P12');
        putenv('FRT_PASSWORD');
    }
});

test('changing fiscal identity clears prior TEST verification and production activation audit', function (): void {
    $business = Business::query()->create([
        'name'=>'Reset Business','tax_number'=>'L12345678A','currency'=>'ALL','timezone'=>'Europe/Tirane','status'=>'active',
    ]);

    $profile = FiscalizationProfile::query()->create([
        'business_id'=>$business->id,
        'provider'=>'direct_dpt',
        'environment'=>'production',
        'status'=>'active',
        'software_code'=>'aa123aa123',
        'certificate_secret_ref'=>'env:OLD_P12',
        'certificate_password_secret_ref'=>'env:OLD_PASSWORD',
        'is_issuer_in_vat'=>true,
        'endpoint'=>'https://prod.example.test',
        'last_verified_at'=>now(),
        'last_test_verified_at'=>now()->subDay(),
        'last_production_verified_at'=>now(),
        'production_activated_at'=>now(),
        'preflight_checked_at'=>now(),
        'preflight_status'=>'ready',
    ]);

    $updated = app(SaveFiscalizationProfile::class)->execute($business, [
        'provider'=>'direct_dpt',
        'environment'=>'production',
        'software_code'=>'bb123bb123',
        'is_issuer_in_vat'=>true,
        'endpoint'=>'https://prod.example.test',
        'certificate_secret_ref'=>null,
        'certificate_password_secret_ref'=>null,
    ]);

    expect($updated->status)->toBe('configured')
        ->and($updated->last_test_verified_at)->toBeNull()
        ->and($updated->last_production_verified_at)->toBeNull()
        ->and($updated->production_activated_at)->toBeNull()
        ->and($updated->preflight_status)->toBeNull()
        ->and($updated->certificate_fingerprint_sha256)->toBeNull();
});

test('monitoring aggregates fiscal states retries and recent failures without secret material', function (): void {
    [$business, $user] = frtFixture('test');
    $locationId = DB::table('locations')->where('business_id',$business->id)->value('id');

    $orderId = (string) Str::ulid();
    DB::table('orders')->insert([
        'id'=>$orderId,'business_id'=>$business->id,'location_id'=>$locationId,'opened_by_user_id'=>$user->id,
        'number'=>'ORD-MON-1','type'=>'takeaway','status'=>'paid','currency'=>'ALL',
        'subtotal'=>'10.0000','discount_total'=>'0.0000','tax_total'=>'2.0000','grand_total'=>'12.0000',
        'opened_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
    ]);

    $invoiceId = (string) Str::ulid();
    DB::table('invoices')->insert([
        'id'=>$invoiceId,'business_id'=>$business->id,'location_id'=>$locationId,'order_id'=>$orderId,
        'created_by_user_id'=>$user->id,'number'=>'INV-MON-1','status'=>'issued','fiscalization_status'=>'retry_pending',
        'currency'=>'ALL','subtotal'=>'10.0000','discount_total'=>'0.0000','tax_total'=>'2.0000','grand_total'=>'12.0000',
        'issued_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
    ]);

    DB::table('invoice_fiscalization_attempts')->insert([
        'id'=>(string)Str::ulid(),'business_id'=>$business->id,'invoice_id'=>$invoiceId,'attempt_no'=>1,
        'provider'=>'direct_dpt','environment'=>'test','status'=>'retry_pending','retryable'=>true,
        'next_retry_at'=>now()->addMinute(),'payload_hash'=>hash('sha256','do-not-expose'),
        'error_code'=>'NETWORK_TIMEOUT','error_message'=>'Temporary network failure',
        'started_at'=>now(),'completed_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
    ]);

    $result = app(FiscalizationMonitoring::class)->forBusiness($business);

    expect($result['attempts_24h']['total'])->toBe(1)
        ->and($result['retry_backlog']['count'])->toBe(1)
        ->and($result['recent_failures'][0]->error_code)->toBe('NETWORK_TIMEOUT')
        ->and(json_encode($result))->not->toContain('do-not-expose')
        ->and(json_encode($result))->not->toContain('certificate_secret_ref');
});


test('production dispatch is blocked when activation or preflight becomes stale', function (): void {
    [$business, $user] = frtFixture('production');
    $profile = FiscalizationProfile::query()->where('business_id', $business->id)->firstOrFail();
    $ca = tempnam(sys_get_temp_dir(), 'dispatch-ca-');
    file_put_contents($ca, 'test-ca');

    try {
        config()->set('fiscalization.production_endpoint', 'https://prod-dpt.example.test/service');
        config()->set('fiscalization.dpt_ca_bundle', $ca);
        config()->set('queue.default', 'redis');

        expect(fn () => app(FiscalizationDispatchGuard::class)->assertCanDispatch($business))
            ->toThrow(ValidationException::class);

        $profile->forceFill([
            'status' => 'active',
            'production_activated_at' => now(),
            'production_activated_by_user_id' => $user->id,
            'preflight_status' => 'ready',
            'preflight_checked_at' => now(),
            'certificate_not_before' => now()->subDay(),
            'certificate_not_after' => now()->addYear(),
        ])->save();

        expect(app(FiscalizationDispatchGuard::class)->assertCanDispatch($business)->id)->toBe($profile->id);

        $profile->forceFill(['preflight_status' => null, 'preflight_checked_at' => null])->save();

        expect(fn () => app(FiscalizationDispatchGuard::class)->assertCanDispatch($business))
            ->toThrow(ValidationException::class);
    } finally {
        @unlink($ca);
    }
});


test('monitoring ignores superseded retry attempts after a later success', function (): void {
    [$business, $user] = frtFixture('test');
    $locationId = DB::table('locations')->where('business_id',$business->id)->value('id');

    $orderId = (string) Str::ulid();
    DB::table('orders')->insert([
        'id'=>$orderId,'business_id'=>$business->id,'location_id'=>$locationId,'opened_by_user_id'=>$user->id,
        'number'=>'ORD-MON-2','type'=>'takeaway','status'=>'paid','currency'=>'ALL',
        'subtotal'=>'10.0000','discount_total'=>'0.0000','tax_total'=>'2.0000','grand_total'=>'12.0000',
        'opened_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
    ]);

    $invoiceId = (string) Str::ulid();
    DB::table('invoices')->insert([
        'id'=>$invoiceId,'business_id'=>$business->id,'location_id'=>$locationId,'order_id'=>$orderId,
        'created_by_user_id'=>$user->id,'number'=>'INV-MON-2','status'=>'issued','fiscalization_status'=>'fiscalized',
        'currency'=>'ALL','subtotal'=>'10.0000','discount_total'=>'0.0000','tax_total'=>'2.0000','grand_total'=>'12.0000',
        'nslf'=>'00112233445566778899AABBCCDDEEFF','nivf'=>'FIC-MON-2','issued_at'=>now(),'fiscalized_at'=>now(),
        'created_at'=>now(),'updated_at'=>now(),
    ]);

    DB::table('invoice_fiscalization_attempts')->insert([
        [
            'id'=>(string)Str::ulid(),'business_id'=>$business->id,'invoice_id'=>$invoiceId,'attempt_no'=>1,
            'provider'=>'direct_dpt','environment'=>'test','status'=>'retry_pending','retryable'=>true,
            'next_retry_at'=>now()->subMinute(),'nslf'=>null,'nivf'=>null,
            'error_code'=>'NETWORK_TIMEOUT','error_message'=>'Old retry',
            'started_at'=>now()->subMinutes(2),'completed_at'=>now()->subMinutes(2),'created_at'=>now(),'updated_at'=>now(),
        ],
        [
            'id'=>(string)Str::ulid(),'business_id'=>$business->id,'invoice_id'=>$invoiceId,'attempt_no'=>2,
            'provider'=>'direct_dpt','environment'=>'test','status'=>'succeeded','retryable'=>false,
            'next_retry_at'=>null,'nslf'=>'00112233445566778899AABBCCDDEEFF','nivf'=>'FIC-MON-2',
            'error_code'=>null,'error_message'=>null,
            'started_at'=>now()->subMinute(),'completed_at'=>now()->subMinute(),'created_at'=>now(),'updated_at'=>now(),
        ],
    ]);

    $result = app(FiscalizationMonitoring::class)->forBusiness($business);

    expect($result['retry_backlog']['count'])->toBe(0)
        ->and(collect($result['recent_failures'])->where('document_id',$invoiceId))->toHaveCount(0)
        ->and($result['attempts_24h']['total'])->toBe(2)
        ->and($result['attempts_24h']['succeeded'])->toBe(1);
});
