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

test('authorized fiscal issue endpoints queue invoice and corrective jobs without synchronous provider calls', function (): void {
    $business=fetBusiness('Endpoint Queue');
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
