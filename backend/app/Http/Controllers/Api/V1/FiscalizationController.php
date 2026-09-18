<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SaveFiscalizationProfileRequest;
use App\Http\Requests\Api\V1\SaveFiscalizationSetupRequest;
use App\Models\Business;
use App\Models\FiscalizationProfile;
use App\Models\Invoice;
use App\Jobs\FiscalizeInvoiceJob;
use App\Jobs\FiscalizeCreditNoteJob;
use App\Models\InvoiceCreditNote;
use App\Services\Fiscalization\SaveFiscalizationProfile;
use App\Services\Fiscalization\SaveFiscalizationSetup;
use App\Services\Fiscalization\FiscalizationPreflight;
use App\Services\Fiscalization\FiscalizationMonitoring;
use App\Services\Fiscalization\ActivateProductionFiscalization;
use App\Services\Fiscalization\FiscalizationDispatchGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class FiscalizationController extends Controller
{
    public function show(): JsonResponse
    {
        $business = app(Business::class);
        $profile = FiscalizationProfile::query()->forBusiness($business)->first();

        return response()->json([
            'data' => $this->resource($profile),
        ]);
    }

    public function update(SaveFiscalizationProfileRequest $request, SaveFiscalizationProfile $save): JsonResponse
    {
        $profile = $save->execute(app(Business::class), $request->validated());

        return response()->json([
            'data' => $this->resource($profile),
        ]);
    }

    public function setup(SaveFiscalizationSetup $setup): JsonResponse
    {
        return response()->json([
            'data' => $setup->read(app(Business::class)),
        ]);
    }

    public function updateSetup(SaveFiscalizationSetupRequest $request, SaveFiscalizationSetup $setup): JsonResponse
    {
        return response()->json([
            'data' => $setup->execute(app(Business::class), $request->validated()),
        ]);
    }

    public function preflight(FiscalizationPreflight $preflight): JsonResponse
    {
        return response()->json([
            'data' => $preflight->run(app(Business::class)),
        ]);
    }

    public function monitoring(FiscalizationMonitoring $monitoring): JsonResponse
    {
        return response()->json([
            'data' => $monitoring->forBusiness(app(Business::class)),
        ]);
    }

    public function activateProduction(ActivateProductionFiscalization $activate): JsonResponse
    {
        $profile = $activate->execute(app(Business::class), request()->user());

        return response()->json([
            'data' => $this->resource($profile),
        ]);
    }

    public function fiscalize(string $invoice, FiscalizationDispatchGuard $guard): JsonResponse
    {
        $business = app(Business::class);
        $row = Invoice::query()->forBusiness($business)->whereKey($invoice)->first();
        abort_unless($row, 404);

        $guard->assertCanDispatch($business);

        if ($row->fiscalization_status === 'fiscalized' || filled($row->nivf)) {
            return response()->json(['message' => 'Invoice is already fiscalized.'], 422);
        }

        if (($row->fiscalization_status ?? 'not_fiscalized') !== 'not_fiscalized') {
            return response()->json([
                'message' => 'Initial fiscalization is allowed only from not_fiscalized state. Use retry for failed or retry-pending invoices.',
            ], 422);
        }

        FiscalizeInvoiceJob::dispatch((string) $business->id, (string) $row->id, false);

        return response()->json([
            'data' => [
                'invoice_id' => $row->id,
                'status' => 'queued',
            ],
        ], 202);
    }

    public function retry(string $invoice, FiscalizationDispatchGuard $guard): JsonResponse
    {
        $business = app(Business::class);
        $row = Invoice::query()->forBusiness($business)->whereKey($invoice)->first();
        abort_unless($row, 404);

        $guard->assertCanDispatch($business);

        if ($row->fiscalization_status === 'fiscalized' || filled($row->nivf)) {
            return response()->json(['message' => 'Invoice is already fiscalized.'], 422);
        }

        if (! in_array($row->fiscalization_status, ['failed','retry_pending'], true)) {
            return response()->json(['message' => 'Only failed or retry-pending invoices can be retried.'], 422);
        }

        FiscalizeInvoiceJob::dispatch((string) $business->id, (string) $row->id, true);

        return response()->json([
            'data' => [
                'invoice_id' => $row->id,
                'status' => 'queued',
            ],
        ], 202);
    }

    public function fiscalizeCreditNote(string $creditNote, FiscalizationDispatchGuard $guard): JsonResponse
    {
        $business = app(Business::class);
        $row = InvoiceCreditNote::query()->forBusiness($business)->whereKey($creditNote)->first();
        abort_unless($row, 404);

        $guard->assertCanDispatch($business);

        if ($row->fiscalization_status === 'fiscalized' || filled($row->nivf)) {
            return response()->json(['message' => 'Corrective document is already fiscalized.'], 422);
        }

        if (($row->fiscalization_status ?? 'not_fiscalized') !== 'not_fiscalized') {
            return response()->json([
                'message' => 'Initial corrective fiscalization is allowed only from not_fiscalized state. Use retry after a failure.',
            ], 422);
        }

        FiscalizeCreditNoteJob::dispatch((string) $business->id, (string) $row->id, false);

        return response()->json([
            'data' => [
                'credit_note_id' => $row->id,
                'status' => 'queued',
            ],
        ], 202);
    }

    public function retryCreditNote(string $creditNote, FiscalizationDispatchGuard $guard): JsonResponse
    {
        $business = app(Business::class);
        $row = InvoiceCreditNote::query()->forBusiness($business)->whereKey($creditNote)->first();
        abort_unless($row, 404);

        $guard->assertCanDispatch($business);

        if ($row->fiscalization_status === 'fiscalized' || filled($row->nivf)) {
            return response()->json(['message' => 'Corrective document is already fiscalized.'], 422);
        }

        if (! in_array($row->fiscalization_status, ['failed','retry_pending'], true)) {
            return response()->json(['message' => 'Only failed or retry-pending corrective documents can be retried.'], 422);
        }

        FiscalizeCreditNoteJob::dispatch((string) $business->id, (string) $row->id, true);

        return response()->json([
            'data' => [
                'credit_note_id' => $row->id,
                'status' => 'queued',
            ],
        ], 202);
    }

    public function invoiceAttempts(string $invoice): JsonResponse
    {
        $business = app(Business::class);
        $row = Invoice::query()->forBusiness($business)->whereKey($invoice)->first();
        abort_unless($row, 404);

        $guard->assertCanDispatch($business);

        $attempts = DB::table('invoice_fiscalization_attempts')
            ->where('business_id', $business->id)
            ->where('invoice_id', $row->id)
            ->orderByDesc('attempt_no')
            ->get([
                'id','attempt_no','provider','environment','status','retryable','next_retry_at',
                'http_status','request_id','nslf','nivf','error_code','error_message',
                'started_at','completed_at','created_at',
            ]);

        return response()->json([
            'data' => [
                'document_type' => 'invoice',
                'document_id' => $row->id,
                'number' => $row->number,
                'fiscalization_status' => $row->fiscalization_status,
                'nslf' => $row->nslf,
                'nivf' => $row->nivf,
                'fiscalization_error' => $row->fiscalization_error,
                'attempts' => $attempts,
            ],
        ]);
    }

    public function creditNoteAttempts(string $creditNote): JsonResponse
    {
        $business = app(Business::class);
        $row = InvoiceCreditNote::query()->forBusiness($business)->whereKey($creditNote)->first();
        abort_unless($row, 404);

        $guard->assertCanDispatch($business);

        $attempts = DB::table('credit_note_fiscalization_attempts')
            ->where('business_id', $business->id)
            ->where('invoice_credit_note_id', $row->id)
            ->orderByDesc('attempt_no')
            ->get([
                'id','attempt_no','provider','environment','status','retryable','next_retry_at',
                'http_status','request_id','nslf','nivf','error_code','error_message',
                'started_at','completed_at','created_at',
            ]);

        return response()->json([
            'data' => [
                'document_type' => 'credit_note',
                'document_id' => $row->id,
                'number' => $row->number,
                'fiscalization_status' => $row->fiscalization_status,
                'nslf' => $row->nslf,
                'nivf' => $row->nivf,
                'fiscalization_error' => $row->fiscalization_error,
                'attempts' => $attempts,
            ],
        ]);
    }

    private function resource(?FiscalizationProfile $profile): array
    {
        return [
            'provider' => $profile?->provider ?? 'direct_dpt',
            'environment' => $profile?->environment ?? 'test',
            'status' => $profile?->status ?? 'unconfigured',
            'software_code' => $profile?->software_code,
            'is_issuer_in_vat' => $profile?->is_issuer_in_vat,
            'endpoint' => $profile?->endpoint,
            'certificate_reference_configured' => filled($profile?->certificate_secret_ref),
            'certificate_password_reference_configured' => filled($profile?->certificate_password_secret_ref),
            'last_verified_at' => $profile?->last_verified_at?->toISOString(),
            'last_test_verified_at' => $profile?->last_test_verified_at?->toISOString(),
            'last_production_verified_at' => $profile?->last_production_verified_at?->toISOString(),
            'production_activated_at' => $profile?->production_activated_at?->toISOString(),
            'preflight_checked_at' => $profile?->preflight_checked_at?->toISOString(),
            'preflight_status' => $profile?->preflight_status,
            'certificate_not_before' => $profile?->certificate_not_before?->toISOString(),
            'certificate_not_after' => $profile?->certificate_not_after?->toISOString(),
            'certificate_fingerprint_sha256' => $profile?->certificate_fingerprint_sha256,
            'ready_for_verification' => filled($profile?->software_code)
                && filled($profile?->endpoint)
                && filled($profile?->certificate_secret_ref)
                && $profile?->is_issuer_in_vat !== null,
        ];
    }
}
