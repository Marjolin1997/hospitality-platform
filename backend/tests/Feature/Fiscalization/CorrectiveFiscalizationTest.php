<?php

use App\Models\Business;
use App\Models\FiscalizationProfile;
use App\Models\InvoiceCreditNote;
use App\Models\Location;
use App\Models\User;
use App\Services\Fiscalization\Contracts\FiscalizationGateway;
use App\Services\Fiscalization\Data\FiscalInvoiceSubmission;
use App\Services\Fiscalization\Data\FiscalizationGatewayResult;
use App\Services\Fiscalization\FiscalizeCreditNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function cftCredentials(): array
{
    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    $dn = [
        'countryName' => 'AL',
        'organizationName' => 'Corrective Test',
        'commonName' => 'Corrective Test Certificate',
    ];
    $csr = openssl_csr_new($dn, $key, ['digest_alg' => 'sha256']);
    $certificate = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);

    $pkcs12 = '';
    openssl_pkcs12_export($certificate, $pkcs12, $key, 'credit-password');

    return [
        'pkcs12' => base64_encode($pkcs12),
        'password' => 'credit-password',
    ];
}

function cftFixture(): array
{
    $business = Business::query()->create([
        'name' => 'Corrective Fiscal Business',
        'legal_name' => 'Corrective Fiscal Business sh.p.k.',
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
        'name' => 'Corrective Operator',
        'email' => Str::lower(Str::random(10)).'@example.test',
        'password' => bcrypt('password'),
    ]);

    $orderId = (string) Str::ulid();
    DB::table('orders')->insert([
        'id' => $orderId,
        'business_id' => $business->id,
        'location_id' => $location->id,
        'opened_by_user_id' => $user->id,
        'number' => 'ORD-CORR-1',
        'type' => 'takeaway',
        'status' => 'refunded',
        'currency' => 'ALL',
        'subtotal' => '100.0000',
        'discount_total' => '0.0000',
        'tax_total' => '20.0000',
        'grand_total' => '120.0000',
        'opened_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $invoiceId = (string) Str::ulid();
    $issuedAt = now()->subMinutes(10);
    DB::table('invoices')->insert([
        'id' => $invoiceId,
        'business_id' => $business->id,
        'location_id' => $location->id,
        'order_id' => $orderId,
        'created_by_user_id' => $user->id,
        'business_name_snapshot' => $business->name,
        'business_legal_name_snapshot' => $business->legal_name,
        'business_tax_number_snapshot' => $business->tax_number,
        'location_name_snapshot' => $location->name,
        'location_address_snapshot' => 'Tirane',
        'fiscal_operator_code_snapshot' => 'cc123cc123',
        'fiscal_business_unit_code_snapshot' => 'bb123bb123',
        'fiscal_tcr_code_snapshot' => 'aa123aa123',
        'number' => 'INV-CORR-1',
        'status' => 'issued',
        'fiscalization_status' => 'fiscalized',
        'fiscal_invoice_type' => 'CASH',
        'fiscal_invoice_number' => '1/2026/aa123aa123',
        'fiscal_ordinal_number' => 1,
        'currency' => 'ALL',
        'subtotal' => '100.0000',
        'discount_total' => '0.0000',
        'tax_total' => '20.0000',
        'grand_total' => '120.0000',
        'nslf' => '00112233445566778899AABBCCDDEEFF',
        'nivf' => 'FIC-ORIGINAL-1',
        'issued_at' => $issuedAt,
        'fiscalized_at' => $issuedAt,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $invoiceLineId = (string) Str::ulid();
    DB::table('invoice_lines')->insert([
        'id' => $invoiceLineId,
        'business_id' => $business->id,
        'invoice_id' => $invoiceId,
        'position' => 1,
        'product_name_snapshot' => 'Kafe',
        'sku_snapshot' => 'KAFE',
        'unit_code_snapshot' => 'C62',
        'unit_label_snapshot' => 'Copë',
        'quantity' => '1.0000',
        'unit_price' => '120.0000',
        'discount_percent' => '0.0000',
        'tax_rate' => '20.0000',
        'line_subtotal' => '100.0000',
        'line_tax' => '20.0000',
        'line_total' => '120.0000',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('invoice_payment_snapshots')->insert([
        'id' => (string) Str::ulid(),
        'business_id' => $business->id,
        'invoice_id' => $invoiceId,
        'position' => 1,
        'method' => 'cash',
        'method_label' => 'Kartëmonedha dhe monedha',
        'amount' => '120.0000',
        'currency' => 'ALL',
        'amount_base' => '120.0000',
        'base_currency' => 'ALL',
        'exchange_rate' => '1.0000000000',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $creditId = (string) Str::ulid();
    DB::table('invoice_credit_notes')->insert([
        'id' => $creditId,
        'business_id' => $business->id,
        'invoice_id' => $invoiceId,
        'created_by_user_id' => $user->id,
        'number' => 'CN-TEST-1',
        'invoice_number_snapshot' => 'INV-CORR-1',
        'original_invoice_nslf_snapshot' => '00112233445566778899AABBCCDDEEFF',
        'fiscal_operator_code_snapshot' => 'cc123cc123',
        'fiscal_business_unit_code_snapshot' => 'bb123bb123',
        'fiscal_tcr_code_snapshot' => 'aa123aa123',
        'original_invoice_issued_at_snapshot' => $issuedAt,
        'status' => 'issued',
        'fiscalization_status' => 'not_fiscalized',
        'fiscal_invoice_type' => 'CASH',
        'currency' => 'ALL',
        'subtotal' => '100.0000',
        'discount_total' => '0.0000',
        'tax_total' => '20.0000',
        'grand_total' => '120.0000',
        'reason' => 'Full order return',
        'idempotency_key' => 'credit-corrective-test',
        'issued_at' => now()->subMinutes(5),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('invoice_credit_note_lines')->insert([
        'id' => (string) Str::ulid(),
        'business_id' => $business->id,
        'invoice_credit_note_id' => $creditId,
        'invoice_line_id' => $invoiceLineId,
        'position' => 1,
        'product_name_snapshot' => 'Kafe',
        'sku_snapshot' => 'KAFE',
        'unit_code_snapshot' => 'C62',
        'unit_label_snapshot' => 'Copë',
        'quantity' => '1.0000',
        'unit_price' => '120.0000',
        'discount_percent' => '0.0000',
        'tax_rate' => '20.0000',
        'line_subtotal' => '100.0000',
        'line_tax' => '20.0000',
        'line_total' => '120.0000',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('business_fiscal_invoice_counters')->insert([
        'business_id' => $business->id,
        'fiscal_year' => (int) now($business->timezone)->format('Y'),
        'scope_key' => 'aa123aa123',
        'last_number' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    FiscalizationProfile::query()->create([
        'business_id' => $business->id,
        'provider' => 'direct_dpt',
        'environment' => 'test',
        'status' => 'configured',
        'software_code' => 'dd123dd123',
        'certificate_secret_ref' => 'env:CFT_P12',
        'certificate_password_secret_ref' => 'env:CFT_PASSWORD',
        'is_issuer_in_vat' => true,
        'endpoint' => 'https://dpt-test.example.test/service',
    ]);

    return [$business, $invoiceId, $creditId];
}

test('full corrective document is fiscalized as a negative invoice referencing original NSLF and issue time', function (): void {
    $credentials = cftCredentials();
    putenv('CFT_P12='.$credentials['pkcs12']);
    putenv('CFT_PASSWORD='.$credentials['password']);

    $gateway = new class implements FiscalizationGateway {
        public ?FiscalInvoiceSubmission $submission = null;

        public function registerInvoice(FiscalInvoiceSubmission $submission, FiscalizationProfile $profile): FiscalizationGatewayResult
        {
            $this->submission = $submission;

            return FiscalizationGatewayResult::success(
                fic: 'FIC-CORRECTIVE-1',
                requestId: 'REQ-CORRECTIVE-1',
            );
        }
    };

    try {
        [$business, $invoiceId, $creditId] = cftFixture();
        $this->app->instance(FiscalizationGateway::class, $gateway);

        $result = app(FiscalizeCreditNote::class)->execute($business, $creditId);

        expect($result['status'])->toBe('fiscalized')
            ->and($gateway->submission)->not->toBeNull()
            ->and($gateway->submission->totalPrice)->toBe('-120.00')
            ->and($gateway->submission->totalWithoutVat)->toBe('-100.00')
            ->and($gateway->submission->totalVat)->toBe('-20.00')
            ->and($gateway->submission->correctiveIicRef)->toBe('00112233445566778899AABBCCDDEEFF')
            ->and($gateway->submission->correctiveIssueDateTime)->not->toBeNull()
            ->and($gateway->submission->items[0]['price_after_vat'])->toBe('-120.00')
            ->and($gateway->submission->payments[0])->toMatchArray(['type'=>'BANKNOTE','amount'=>'-120.00']);

        $credit = InvoiceCreditNote::query()->findOrFail($creditId);
        expect($credit->fiscalization_status)->toBe('fiscalized')
            ->and($credit->nivf)->toBe('FIC-CORRECTIVE-1')
            ->and($credit->nslf)->toMatch('/^[0-9A-F]{32}$/')
            ->and($credit->fiscal_invoice_number)->toContain('/'.now($business->timezone)->format('Y').'/aa123aa123')
            ->and(DB::table('credit_note_fiscalization_attempts')->where('invoice_credit_note_id',$creditId)->value('status'))->toBe('succeeded')
            ->and(DB::table('invoices')->where('id',$invoiceId)->value('nivf'))->toBe('FIC-ORIGINAL-1');
    } finally {
        putenv('CFT_P12');
        putenv('CFT_PASSWORD');
    }
});

test('corrective fiscalization is blocked until the original invoice is successfully fiscalized', function (): void {
    $credentials = cftCredentials();
    putenv('CFT_P12='.$credentials['pkcs12']);
    putenv('CFT_PASSWORD='.$credentials['password']);

    try {
        [$business, $invoiceId, $creditId] = cftFixture();
        DB::table('invoices')->where('id',$invoiceId)->update([
            'fiscalization_status' => 'retry_pending',
            'nivf' => null,
            'updated_at' => now(),
        ]);

        expect(fn () => app(FiscalizeCreditNote::class)->execute($business, $creditId))
            ->toThrow(ValidationException::class);

        expect(DB::table('credit_note_fiscalization_attempts')->where('invoice_credit_note_id',$creditId)->count())->toBe(0)
            ->and(DB::table('invoice_credit_notes')->where('id',$creditId)->value('fiscalization_status'))->toBe('not_fiscalized');
    } finally {
        putenv('CFT_P12');
        putenv('CFT_PASSWORD');
    }
});
