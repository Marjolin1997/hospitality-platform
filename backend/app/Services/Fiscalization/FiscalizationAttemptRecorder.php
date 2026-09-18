<?php

namespace App\Services\Fiscalization;

use App\Models\Business;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class FiscalizationAttemptRecorder
{
    public function __construct(private readonly FiscalizationRetryPolicy $retryPolicy) {}

    public function begin(Business $business, string $invoiceId, string $provider, string $environment, string $payloadHash): object
    {
        return DB::transaction(function () use ($business, $invoiceId, $provider, $environment, $payloadHash): object {
            $invoice = Invoice::query()->forBusiness($business)->whereKey($invoiceId)->lockForUpdate()->first();
            abort_unless($invoice, 404);

            if ($invoice->fiscalization_status === 'fiscalized' || filled($invoice->nivf)) {
                throw ValidationException::withMessages(['invoice' => 'This invoice is already fiscalized.']);
            }

            $attemptNo = ((int) $invoice->fiscalization_attempts) + 1;
            $attemptId = (string) Str::ulid();

            DB::table('invoice_fiscalization_attempts')->insert([
                'id' => $attemptId,
                'business_id' => $business->id,
                'invoice_id' => $invoice->id,
                'attempt_no' => $attemptNo,
                'provider' => $provider,
                'environment' => $environment,
                'status' => 'processing',
                'retryable' => false,
                'payload_hash' => $payloadHash,
                'started_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $invoice->forceFill([
                'fiscalization_status' => 'processing',
                'fiscalization_attempts' => $attemptNo,
                'fiscalization_error' => null,
            ])->save();

            return DB::table('invoice_fiscalization_attempts')->where('id', $attemptId)->firstOrFail();
        }, attempts: 3);
    }

    public function succeed(
        Business $business,
        string $attemptId,
        string $nslf,
        string $nivf,
        ?string $verificationUrl,
        ?string $qrPayload,
        ?string $requestId = null,
    ): object {
        return DB::transaction(function () use ($business, $attemptId, $nslf, $nivf, $verificationUrl, $qrPayload, $requestId): object {
            $attempt = DB::table('invoice_fiscalization_attempts')
                ->where('business_id', $business->id)
                ->where('id', $attemptId)
                ->lockForUpdate()
                ->first();
            abort_unless($attempt, 404);

            $invoice = Invoice::query()->forBusiness($business)->whereKey($attempt->invoice_id)->lockForUpdate()->firstOrFail();

            if ($attempt->status === 'succeeded') {
                return $invoice;
            }

            DB::table('invoice_fiscalization_attempts')->where('id', $attemptId)->update([
                'status' => 'succeeded',
                'retryable' => false,
                'next_retry_at' => null,
                'request_id' => $requestId,
                'nslf' => $nslf,
                'nivf' => $nivf,
                'error_code' => null,
                'error_message' => null,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

            $invoice->forceFill([
                'fiscalization_status' => 'fiscalized',
                'nslf' => $nslf,
                'nivf' => $nivf,
                'verification_url' => $verificationUrl,
                'qr_payload' => $qrPayload,
                'fiscalized_at' => now(),
                'fiscalization_error' => null,
            ])->save();

            return $invoice->refresh();
        }, attempts: 3);
    }

    public function fail(
        Business $business,
        string $attemptId,
        string $errorCode,
        string $errorMessage,
        bool $retryable,
        ?int $httpStatus = null,
    ): object {
        return DB::transaction(function () use ($business, $attemptId, $errorCode, $errorMessage, $retryable, $httpStatus): object {
            $attempt = DB::table('invoice_fiscalization_attempts')
                ->where('business_id', $business->id)
                ->where('id', $attemptId)
                ->lockForUpdate()
                ->first();
            abort_unless($attempt, 404);

            $invoice = Invoice::query()->forBusiness($business)->whereKey($attempt->invoice_id)->lockForUpdate()->firstOrFail();
            $nextRetryAt = $retryable ? $this->retryPolicy->nextRetryAt((int) $attempt->attempt_no) : null;
            $status = $retryable ? 'retry_pending' : 'failed';

            DB::table('invoice_fiscalization_attempts')->where('id', $attemptId)->update([
                'status' => $status,
                'retryable' => $retryable,
                'next_retry_at' => $nextRetryAt,
                'http_status' => $httpStatus,
                'error_code' => $errorCode,
                'error_message' => Str::limit($errorMessage, 2000, ''),
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

            $invoice->forceFill([
                'fiscalization_status' => $status,
                'fiscalization_error' => Str::limit($errorMessage, 2000, ''),
            ])->save();

            return DB::table('invoice_fiscalization_attempts')->where('id', $attemptId)->firstOrFail();
        }, attempts: 3);
    }
}
