<?php

use App\Jobs\FiscalizeCreditNoteJob;
use App\Jobs\FiscalizeInvoiceJob;
use App\Models\Business;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    Queue::fake();
});

function fetBusiness(string $name): Business
{
    return Business::query()->create([
        'name'=>$name,
        'legal_name'=>$name.' sh.p.k.',
        'tax_number'=>'L12345678A',
        'currency'=>'ALL',
        'timezone'=>'Europe/Tirane',
        'status'=>'active',
    ]);
}

function fetUser(Business $business, array $permissionKeys): User
{
    $user=User::query()->create([
        'name'=>'Fiscal Endpoint User',
        'email'=>Str::lower(Str::random(12)).'@example.test',
        'password'=>bcrypt('password'),
    ]);
    $role=Role::query()->create([
        'business_id'=>$business->id,
        'name'=>'Fiscal Endpoint Role',
        'slug'=>'fiscal-endpoint-'.Str::lower(Str::random(8)),
        'is_system'=>false,
    ]);
    $role->permissions()->sync(Permission::query()->whereIn('key',$permissionKeys)->pluck('id'));
    $user->businesses()->attach($business->id,['role_id'=>$role->id,'status'=>'active']);
    Sanctum::actingAs($user);

    return $user;
}

function fetDocuments(Business $business, User $user): array
{
    $locationId=(string)Str::ulid();
    DB::table('locations')->insert([
        'id'=>$locationId,'business_id'=>$business->id,'name'=>'Tirana','code'=>'TIR',
        'type'=>'bar','is_active'=>true,'created_at'=>now(),'updated_at'=>now(),
    ]);

    $orderId=(string)Str::ulid();
    DB::table('orders')->insert([
        'id'=>$orderId,'business_id'=>$business->id,'location_id'=>$locationId,'opened_by_user_id'=>$user->id,
        'number'=>'ORD-'.Str::upper(Str::random(7)),'type'=>'takeaway','status'=>'paid','currency'=>'ALL',
        'subtotal'=>'100.0000','discount_total'=>'0.0000','tax_total'=>'20.0000','grand_total'=>'120.0000',
        'opened_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
    ]);

    $invoiceId=(string)Str::ulid();
    DB::table('invoices')->insert([
        'id'=>$invoiceId,'business_id'=>$business->id,'location_id'=>$locationId,'order_id'=>$orderId,'created_by_user_id'=>$user->id,
        'number'=>'INV-'.Str::upper(Str::random(7)),'status'=>'issued','fiscalization_status'=>'not_fiscalized',
        'fiscal_invoice_type'=>'CASH','currency'=>'ALL','subtotal'=>'100.0000','discount_total'=>'0.0000',
        'tax_total'=>'20.0000','grand_total'=>'120.0000','issued_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
    ]);

    $creditId=(string)Str::ulid();
    DB::table('invoice_credit_notes')->insert([
        'id'=>$creditId,'business_id'=>$business->id,'invoice_id'=>$invoiceId,'created_by_user_id'=>$user->id,
        'number'=>'CN-'.Str::upper(Str::random(7)),'invoice_number_snapshot'=>'INV-SNAPSHOT',
        'status'=>'issued','fiscalization_status'=>'not_fiscalized','fiscal_invoice_type'=>'CASH','currency'=>'ALL',
        'subtotal'=>'100.0000','discount_total'=>'0.0000','tax_total'=>'20.0000','grand_total'=>'120.0000',
        'reason'=>'Test correction','idempotency_key'=>'idem-'.Str::uuid(),'issued_at'=>now(),
        'created_at'=>now(),'updated_at'=>now(),
    ]);

    return [$invoiceId,$creditId,$orderId,$locationId];
}

function fetConfiguredProfile(Business $business): void
{
    DB::table('fiscalization_profiles')->updateOrInsert(
        ['business_id'=>$business->id],
        [
            'id'=>(string)Str::ulid(),
            'provider'=>'direct_dpt',
            'environment'=>'test',
            'status'=>'configured',
            'software_code'=>'dd123dd123',
            'certificate_secret_ref'=>'env:FET_QUEUE_P12',
            'is_issuer_in_vat'=>true,
            'endpoint'=>'https://test-dpt.example.test/service',
            'created_at'=>now(),
            'updated_at'=>now(),
        ],
    );
}


test('authorized fiscal issue endpoints queue invoice and corrective jobs without synchronous provider calls', function (): void {
    $business=fetBusiness('Endpoint Queue');
    fetConfiguredProfile($business);
    $user=fetUser($business,['fiscalization.issue','fiscalization.retry','fiscalization.view','invoices.view']);
    [$invoiceId,$creditId]=fetDocuments($business,$user);
    $headers=['X-Business-Id'=>$business->id];

    $this->postJson("/api/v1/invoices/{$invoiceId}/fiscalize",[], $headers)
        ->assertStatus(202)
        ->assertJsonPath('data.status','queued');

    $this->postJson("/api/v1/invoice-credit-notes/{$creditId}/fiscalize",[], $headers)
        ->assertStatus(202)
        ->assertJsonPath('data.status','queued');

    Queue::assertPushed(FiscalizeInvoiceJob::class, fn (FiscalizeInvoiceJob $job): bool =>
        $job->businessId===$business->id && $job->invoiceId===$invoiceId && $job->subsequentDelivery===false
    );
    Queue::assertPushed(FiscalizeCreditNoteJob::class, fn (FiscalizeCreditNoteJob $job): bool =>
        $job->businessId===$business->id && $job->creditNoteId===$creditId && $job->subsequentDelivery===false
    );
});

test('fiscal retry endpoints require failed state and mark queued retry as subsequent delivery', function (): void {
    $business=fetBusiness('Endpoint Retry');
    fetConfiguredProfile($business);
    $user=fetUser($business,['fiscalization.issue','fiscalization.retry','fiscalization.view','invoices.view']);
    [$invoiceId,$creditId]=fetDocuments($business,$user);
    $headers=['X-Business-Id'=>$business->id];

    $this->postJson("/api/v1/invoices/{$invoiceId}/fiscalization/retry",[], $headers)->assertStatus(422);
    $this->postJson("/api/v1/invoice-credit-notes/{$creditId}/fiscalization/retry",[], $headers)->assertStatus(422);

    DB::table('invoices')->where('id',$invoiceId)->update(['fiscalization_status'=>'retry_pending','updated_at'=>now()]);
    DB::table('invoice_credit_notes')->where('id',$creditId)->update(['fiscalization_status'=>'failed','updated_at'=>now()]);

    $this->postJson("/api/v1/invoices/{$invoiceId}/fiscalization/retry",[], $headers)->assertStatus(202);
    $this->postJson("/api/v1/invoice-credit-notes/{$creditId}/fiscalization/retry",[], $headers)->assertStatus(202);

    Queue::assertPushed(FiscalizeInvoiceJob::class, fn (FiscalizeInvoiceJob $job): bool => $job->subsequentDelivery===true);
    Queue::assertPushed(FiscalizeCreditNoteJob::class, fn (FiscalizeCreditNoteJob $job): bool => $job->subsequentDelivery===true);
});

test('initial fiscalization cannot bypass the retry state machine', function (): void {
    $business=fetBusiness('Endpoint State Machine');
    fetConfiguredProfile($business);
    $user=fetUser($business,['fiscalization.issue','fiscalization.retry','fiscalization.view','invoices.view']);
    [$invoiceId,$creditId]=fetDocuments($business,$user);
    $headers=['X-Business-Id'=>$business->id];

    DB::table('invoices')->where('id',$invoiceId)->update(['fiscalization_status'=>'processing','updated_at'=>now()]);
    DB::table('invoice_credit_notes')->where('id',$creditId)->update(['fiscalization_status'=>'retry_pending','updated_at'=>now()]);

    $this->postJson("/api/v1/invoices/{$invoiceId}/fiscalize",[], $headers)->assertStatus(422);
    $this->postJson("/api/v1/invoice-credit-notes/{$creditId}/fiscalize",[], $headers)->assertStatus(422);

    Queue::assertNothingPushed();
});

test('corrective document detail is tenant scoped and exposes immutable print snapshots', function (): void {
    $business=fetBusiness('Endpoint Credit Detail');
    $user=fetUser($business,['fiscalization.view','invoices.view']);
    [$invoiceId,$creditId]=fetDocuments($business,$user);
    $headers=['X-Business-Id'=>$business->id];

    DB::table('invoice_lines')->insert([
        'id'=>(string)Str::ulid(),'business_id'=>$business->id,'invoice_id'=>$invoiceId,'position'=>1,
        'product_name_snapshot'=>'Coffee','quantity'=>'1.0000','unit_price'=>'120.0000','tax_rate'=>'20.0000',
        'line_subtotal'=>'100.0000','line_tax'=>'20.0000','line_total'=>'120.0000',
        'created_at'=>now(),'updated_at'=>now(),
    ]);
    DB::table('invoice_credit_note_lines')->insert([
        'id'=>(string)Str::ulid(),'business_id'=>$business->id,'invoice_credit_note_id'=>$creditId,
        'position'=>1,'product_name_snapshot'=>'Coffee','unit_code_snapshot'=>'C62','unit_label_snapshot'=>'Copë',
        'quantity'=>'1.0000','unit_price'=>'120.0000','discount_percent'=>'0.0000','tax_rate'=>'20.0000',
        'line_subtotal'=>'100.0000','line_tax'=>'20.0000','line_total'=>'120.0000',
        'created_at'=>now(),'updated_at'=>now(),
    ]);
    DB::table('invoice_payment_snapshots')->insert([
        'id'=>(string)Str::ulid(),'business_id'=>$business->id,'invoice_id'=>$invoiceId,'position'=>1,
        'method'=>'cash','method_label'=>'Kartëmonedha dhe monedha','amount'=>'120.0000','currency'=>'ALL',
        'amount_base'=>'120.0000','base_currency'=>'ALL','exchange_rate'=>'1.0000000000',
        'created_at'=>now(),'updated_at'=>now(),
    ]);

    $this->getJson("/api/v1/invoice-credit-notes/{$creditId}",$headers)->assertOk()
        ->assertJsonPath('data.id',$creditId)
        ->assertJsonPath('data.original_invoice.id',$invoiceId)
        ->assertJsonPath('data.lines.0.product_name_snapshot','Coffee')
        ->assertJsonPath('data.payments.0.method_label','Kartëmonedha dhe monedha');
});

test('fiscal endpoints and diagnostics never cross tenant boundaries', function (): void {
    $a=fetBusiness('Endpoint Tenant A');
    $userA=fetUser($a,['fiscalization.issue','fiscalization.retry','fiscalization.view','invoices.view']);
    fetDocuments($a,$userA);

    $b=fetBusiness('Endpoint Tenant B');
    $userB=User::query()->create([
        'name'=>'B owner','email'=>Str::lower(Str::random(12)).'@example.test','password'=>bcrypt('password'),
    ]);
    [$invoiceB,$creditB]=fetDocuments($b,$userB);

    $headers=['X-Business-Id'=>$a->id];

    $this->postJson("/api/v1/invoices/{$invoiceB}/fiscalize",[], $headers)->assertNotFound();
    $this->postJson("/api/v1/invoice-credit-notes/{$creditB}/fiscalize",[], $headers)->assertNotFound();
    $this->getJson("/api/v1/invoices/{$invoiceB}/fiscalization/attempts",$headers)->assertNotFound();
    $this->getJson("/api/v1/invoice-credit-notes/{$creditB}/fiscalization/attempts",$headers)->assertNotFound();
    $this->getJson("/api/v1/invoice-credit-notes/{$creditB}",$headers)->assertNotFound();

    Queue::assertNothingPushed();
});

test('diagnostics expose safe operational history but not payload hashes or certificate material', function (): void {
    $business=fetBusiness('Endpoint Diagnostics');
    $user=fetUser($business,['fiscalization.view','invoices.view']);
    [$invoiceId,$creditId]=fetDocuments($business,$user);
    $headers=['X-Business-Id'=>$business->id];

    DB::table('invoice_fiscalization_attempts')->insert([
        'id'=>(string)Str::ulid(),'business_id'=>$business->id,'invoice_id'=>$invoiceId,'attempt_no'=>1,
        'provider'=>'direct_dpt','environment'=>'test','status'=>'failed','retryable'=>false,
        'payload_hash'=>hash('sha256','secret-payload'),'error_code'=>'XSD_INVALID','error_message'=>'Safe validation error',
        'started_at'=>now(),'completed_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
    ]);
    DB::table('credit_note_fiscalization_attempts')->insert([
        'id'=>(string)Str::ulid(),'business_id'=>$business->id,'invoice_credit_note_id'=>$creditId,'attempt_no'=>1,
        'provider'=>'direct_dpt','environment'=>'test','status'=>'retry_pending','retryable'=>true,
        'payload_hash'=>hash('sha256','credit-payload'),'error_code'=>'NETWORK_TIMEOUT','error_message'=>'Temporary network error',
        'started_at'=>now(),'completed_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
    ]);

    $invoice=$this->getJson("/api/v1/invoices/{$invoiceId}/fiscalization/attempts",$headers)->assertOk()
        ->assertJsonPath('data.attempts.0.error_code','XSD_INVALID')
        ->assertJsonMissingPath('data.attempts.0.payload_hash');

    $credit=$this->getJson("/api/v1/invoice-credit-notes/{$creditId}/fiscalization/attempts",$headers)->assertOk()
        ->assertJsonPath('data.attempts.0.error_code','NETWORK_TIMEOUT')
        ->assertJsonMissingPath('data.attempts.0.payload_hash');

    expect(json_encode($invoice->json()))->not->toContain('secret-payload')
        ->and(json_encode($credit->json()))->not->toContain('credit-payload');
});


test('fiscal health endpoints separate view manage and production activation permissions', function (): void {
    $business=fetBusiness('Endpoint Fiscal Health');
    $viewer=fetUser($business,['fiscalization.view']);
    $headers=['X-Business-Id'=>$business->id];

    $this->getJson('/api/v1/fiscalization/monitoring',$headers)->assertOk()
        ->assertJsonStructure(['data'=>['documents','attempts_24h','retry_backlog','recent_failures','generated_at']]);

    $this->postJson('/api/v1/fiscalization/preflight',[],$headers)->assertForbidden();
    $this->postJson('/api/v1/fiscalization/activate-production',[],$headers)->assertForbidden();

    $manager=fetUser($business,['fiscalization.view','fiscalization.manage']);
    $this->postJson('/api/v1/fiscalization/preflight',[], $headers)->assertOk()
        ->assertJsonPath('data.status','blocked');
    $this->postJson('/api/v1/fiscalization/activate-production',[], $headers)->assertForbidden();

    $activator=fetUser($business,['fiscalization.view','fiscalization.activate_production']);
    $this->postJson('/api/v1/fiscalization/activate-production',[], $headers)->assertNotFound();
});


function fetReadinessCredentials(): array
{
    $key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
    $csr=openssl_csr_new([
        'countryName'=>'AL',
        'organizationName'=>'Endpoint Readiness',
        'commonName'=>'Endpoint Readiness Certificate',
    ],$key,['digest_alg'=>'sha256']);
    $certificate=openssl_csr_sign($csr,null,$key,365,['digest_alg'=>'sha256']);
    $pkcs12='';
    openssl_pkcs12_export($certificate,$pkcs12,$key,'endpoint-password');

    return ['pkcs12'=>base64_encode($pkcs12),'password'=>'endpoint-password'];
}

test('owner-only production activation endpoint succeeds only after TEST verification and production preflight', function (): void {
    $credentials=fetReadinessCredentials();
    putenv('FET_P12='.$credentials['pkcs12']);
    putenv('FET_PASSWORD='.$credentials['password']);
    $ca=tempnam(sys_get_temp_dir(),'fet-ca-');
    file_put_contents($ca,'test-ca');

    try {
        $business=fetBusiness('Endpoint Production Activation');
        $user=fetUser($business,['fiscalization.view','fiscalization.manage','fiscalization.activate_production']);
        [,,$orderId,$locationId]=fetDocuments($business,$user);

        DB::table('locations')->where('id',$locationId)->update([
            'fiscal_business_unit_code'=>'bb123bb123',
            'updated_at'=>now(),
        ]);
        DB::table('business_user')
            ->where('business_id',$business->id)
            ->where('user_id',$user->id)
            ->update(['fiscal_operator_code'=>'cc123cc123']);
        DB::table('cash_registers')->insert([
            'id'=>(string)Str::ulid(),'business_id'=>$business->id,'location_id'=>$locationId,
            'name'=>'Main','code'=>'MAIN','fiscal_tcr_code'=>'aa123aa123','is_active'=>true,
            'created_at'=>now(),'updated_at'=>now(),
        ]);

        DB::table('fiscalization_profiles')->insert([
            'id'=>(string)Str::ulid(),'business_id'=>$business->id,'provider'=>'direct_dpt',
            'environment'=>'production','status'=>'configured','software_code'=>'dd123dd123',
            'certificate_secret_ref'=>'env:FET_P12','certificate_password_secret_ref'=>'env:FET_PASSWORD',
            'is_issuer_in_vat'=>true,'endpoint'=>'https://prod-dpt.example.test/service',
            'last_test_verified_at'=>now(),'last_verified_at'=>now(),
            'created_at'=>now(),'updated_at'=>now(),
        ]);

        config()->set('fiscalization.production_endpoint','https://prod-dpt.example.test/service');
        config()->set('fiscalization.dpt_ca_bundle',$ca);
        config()->set('queue.default','redis');

        $headers=['X-Business-Id'=>$business->id];

        $this->postJson('/api/v1/fiscalization/preflight',[],$headers)->assertOk()
            ->assertJsonPath('data.status','ready')
            ->assertJsonPath('data.can_activate_production',true);

        $this->postJson('/api/v1/fiscalization/activate-production',[],$headers)->assertOk()
            ->assertJsonPath('data.status','active')
            ->assertJsonPath('data.environment','production');

        expect(DB::table('fiscalization_profiles')->where('business_id',$business->id)->value('production_activated_at'))
            ->not->toBeNull();
    } finally {
        @unlink($ca);
        putenv('FET_P12');
        putenv('FET_PASSWORD');
    }
});
