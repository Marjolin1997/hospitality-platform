<?php

use App\Models\Business;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\User;
use App\Services\Fiscalization\FiscalizationAttemptRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function farInvoiceFixture(): array
{
    $business = Business::query()->create([
        'name'=>'Fiscal Attempt Business','legal_name'=>'Fiscal Attempt sh.p.k.','tax_number'=>'L12345678A',
        'currency'=>'ALL','timezone'=>'Europe/Tirane','status'=>'active',
    ]);
    $location = Location::query()->create([
        'business_id'=>$business->id,'name'=>'Tirana','code'=>'TIR','type'=>'bar','is_active'=>true,
    ]);
    $user = User::query()->create([
        'name'=>'Fiscal User','email'=>Str::lower(Str::random(10)).'@example.test','password'=>bcrypt('password'),
    ]);
    $orderId=(string)Str::ulid();
    DB::table('orders')->insert([
        'id'=>$orderId,'business_id'=>$business->id,'location_id'=>$location->id,'opened_by_user_id'=>$user->id,
        'number'=>'ORD-'.Str::upper(Str::random(8)),'type'=>'takeaway','status'=>'paid','currency'=>'ALL',
        'subtotal'=>'100.0000','discount_total'=>'0.0000','tax_total'=>'20.0000','grand_total'=>'120.0000',
        'opened_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
    ]);
    $invoice=Invoice::query()->create([
        'business_id'=>$business->id,'location_id'=>$location->id,'order_id'=>$orderId,'created_by_user_id'=>$user->id,
        'number'=>'INV-'.Str::upper(Str::random(8)),'status'=>'issued','currency'=>'ALL',
        'subtotal'=>'100.0000','discount_total'=>'0.0000','tax_total'=>'20.0000','grand_total'=>'120.0000',
        'fiscalization_status'=>'not_fiscalized','issued_at'=>now(),
    ]);

    return [$business,$invoice];
}

test('fiscalization attempt recorder transitions invoice to fiscalized exactly once', function (): void {
    [$business,$invoice]=farInvoiceFixture();
    $recorder=app(FiscalizationAttemptRecorder::class);

    $attempt=$recorder->begin($business,$invoice->id,'direct_dpt','test',hash('sha256','payload'));
    expect($attempt->attempt_no)->toBe(1)
        ->and($attempt->status)->toBe('processing')
        ->and(Invoice::query()->findOrFail($invoice->id)->fiscalization_status)->toBe('processing');

    $result=$recorder->succeed(
        $business,$attempt->id,'AABBCCDDEEFF00112233445566778899','FIC-123',
        'https://example.test/verify?iic=AABB','https://example.test/verify?iic=AABB','REQ-1',
    );

    expect($result->fiscalization_status)->toBe('fiscalized')
        ->and($result->nslf)->toBe('AABBCCDDEEFF00112233445566778899')
        ->and($result->nivf)->toBe('FIC-123')
        ->and(DB::table('invoice_fiscalization_attempts')->where('id',$attempt->id)->value('status'))->toBe('succeeded');

    expect(fn ()=>$recorder->begin($business,$invoice->id,'direct_dpt','test',hash('sha256','same')))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

test('retryable fiscalization failure persists backoff without losing invoice history', function (): void {
    [$business,$invoice]=farInvoiceFixture();
    $recorder=app(FiscalizationAttemptRecorder::class);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-18T12:00:00Z'));
    try {
        $attempt=$recorder->begin($business,$invoice->id,'direct_dpt','test',hash('sha256','payload'));
        $failed=$recorder->fail($business,$attempt->id,'NETWORK_TIMEOUT','DPT TEST endpoint timed out.',true,504);

        expect($failed->status)->toBe('retry_pending')
            ->and((bool)$failed->retryable)->toBeTrue()
            ->and((int)$failed->http_status)->toBe(504)
            ->and((string)$failed->next_retry_at)->toContain('2026-09-18 12:01:00')
            ->and(Invoice::query()->findOrFail($invoice->id)->fiscalization_status)->toBe('retry_pending')
            ->and(Invoice::query()->findOrFail($invoice->id)->fiscalization_attempts)->toBe(1);
    } finally {
        CarbonImmutable::setTestNow();
    }
});

test('non retryable fiscalization failure becomes terminal without erasing invoice', function (): void {
    [$business,$invoice]=farInvoiceFixture();
    $recorder=app(FiscalizationAttemptRecorder::class);

    $attempt=$recorder->begin($business,$invoice->id,'direct_dpt','test',hash('sha256','payload'));
    $failed=$recorder->fail($business,$attempt->id,'XSD_INVALID','Invoice payload does not validate.',false,400);

    expect($failed->status)->toBe('failed')
        ->and((bool)$failed->retryable)->toBeFalse()
        ->and($failed->next_retry_at)->toBeNull()
        ->and(Invoice::query()->findOrFail($invoice->id)->fiscalization_status)->toBe('failed')
        ->and(Invoice::query()->findOrFail($invoice->id)->grand_total)->toBe('120.0000');
});
