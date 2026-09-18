<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SaveFiscalizationProfileRequest;
use App\Models\Business;
use App\Models\FiscalizationProfile;
use App\Models\Invoice;
use App\Jobs\FiscalizeInvoiceJob;
use App\Services\Fiscalization\SaveFiscalizationProfile;
use Illuminate\Http\JsonResponse;

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

    public function fiscalize(string $invoice): JsonResponse
    {
        $business = app(Business::class);
        $row = Invoice::query()->forBusiness($business)->whereKey($invoice)->first();
        abort_unless($row, 404);

        if ($row->fiscalization_status === 'fiscalized' || filled($row->nivf)) {
            return response()->json(['message' => 'Invoice is already fiscalized.'], 422);
        }

        FiscalizeInvoiceJob::dispatch((string) $business->id, (string) $row->id, false);

        return response()->json([
            'data' => [
                'invoice_id' => $row->id,
                'status' => 'queued',
            ],
        ], 202);
    }

    public function retry(string $invoice): JsonResponse
    {
        $business = app(Business::class);
        $row = Invoice::query()->forBusiness($business)->whereKey($invoice)->first();
        abort_unless($row, 404);

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
            'ready_for_verification' => filled($profile?->software_code)
                && filled($profile?->endpoint)
                && filled($profile?->certificate_secret_ref)
                && $profile?->is_issuer_in_vat !== null,
        ];
    }
}
