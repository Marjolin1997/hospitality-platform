<?php

namespace App\Services\Fiscalization;

use App\Models\Business;
use App\Models\InvoiceCreditNote;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CreditNoteFiscalizationAttemptRecorder
{
    public function __construct(private readonly FiscalizationRetryPolicy $retryPolicy) {}

    public function begin(Business $business, string $creditNoteId, string $provider, string $environment, string $payloadHash): object
    {
        return DB::transaction(function () use ($business, $creditNoteId, $provider, $environment, $payloadHash): object {
            $credit = InvoiceCreditNote::query()
                ->forBusiness($business)
                ->whereKey($creditNoteId)
                ->lockForUpdate()
                ->first();
            abort_unless($credit, 404);

            if ($credit->fiscalization_status === 'fiscalized' || filled($credit->nivf)) {
                throw ValidationException::withMessages(['credit_note' => 'This corrective document is already fiscalized.']);
            }

            $attemptNo = ((int) $credit->fiscalization_attempts) + 1;
            $attemptId = (string) Str::ulid();

            DB::table('credit_note_fiscalization_attempts')->insert([
                'id' => $attemptId,
                'business_id' => $business->id,
                'invoice_credit_note_id' => $credit->id,
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

            $credit->forceFill([
                'fiscalization_status' => 'processing',
                'fiscalization_attempts' => $attemptNo,
                'fiscalization_error' => null,
            ])->save();

            return DB::table('credit_note_fiscalization_attempts')->where('id', $attemptId)->firstOrFail();
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
    ): InvoiceCreditNote {
        return DB::transaction(function () use ($business, $attemptId, $nslf, $nivf, $verificationUrl, $qrPayload, $requestId): InvoiceCreditNote {
            $attempt = DB::table('credit_note_fiscalization_attempts')
                ->where('business_id', $business->id)
                ->where('id', $attemptId)
                ->lockForUpdate()
                ->first();
            abort_unless($attempt, 404);

            $credit = InvoiceCreditNote::query()
                ->forBusiness($business)
                ->whereKey($attempt->invoice_credit_note_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($attempt->status === 'succeeded') {
                return $credit;
            }

            DB::table('credit_note_fiscalization_attempts')->where('id', $attemptId)->update([
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

            $credit->forceFill([
                'fiscalization_status' => 'fiscalized',
                'nslf' => $nslf,
                'nivf' => $nivf,
                'verification_url' => $verificationUrl,
                'qr_payload' => $qrPayload,
                'fiscalized_at' => now(),
                'fiscalization_error' => null,
            ])->save();

            return $credit->refresh();
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
            $attempt = DB::table('credit_note_fiscalization_attempts')
                ->where('business_id', $business->id)
                ->where('id', $attemptId)
                ->lockForUpdate()
                ->first();
            abort_unless($attempt, 404);

            $credit = InvoiceCreditNote::query()
                ->forBusiness($business)
                ->whereKey($attempt->invoice_credit_note_id)
                ->lockForUpdate()
                ->firstOrFail();

            $nextRetryAt = $retryable ? $this->retryPolicy->nextRetryAt((int) $attempt->attempt_no) : null;
            $status = $retryable ? 'retry_pending' : 'failed';

            DB::table('credit_note_fiscalization_attempts')->where('id', $attemptId)->update([
                'status' => $status,
                'retryable' => $retryable,
                'next_retry_at' => $nextRetryAt,
                'http_status' => $httpStatus,
                'error_code' => $errorCode,
                'error_message' => Str::limit($errorMessage, 2000, ''),
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

            $credit->forceFill([
                'fiscalization_status' => $status,
                'fiscalization_error' => Str::limit($errorMessage, 2000, ''),
            ])->save();

            return DB::table('credit_note_fiscalization_attempts')->where('id', $attemptId)->firstOrFail();
        }, attempts: 3);
    }
}
