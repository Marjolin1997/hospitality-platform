<?php

namespace App\Services\Fiscalization;

use App\Models\Business;
use App\Services\Fiscalization\Contracts\FiscalizationGateway;
use Illuminate\Support\Facades\DB;

final class FiscalizeCreditNote
{
    public function __construct(
        private readonly FiscalCreditNoteSubmissionFactory $factory,
        private readonly FiscalizationGateway $gateway,
        private readonly CreditNoteFiscalizationAttemptRecorder $attempts,
        private readonly QrVerificationUrlGenerator $qr,
    ) {}

    /** @return array{credit_note:object|null,attempt:object|null,status:string} */
    public function execute(Business $business, string $creditNoteId, bool $subsequentDelivery = false): array
    {
        $prepared = $this->factory->prepare($business, $creditNoteId, $subsequentDelivery);
        $submission = $prepared->submission;
        $profile = $prepared->profile;

        $attempt = $this->attempts->begin(
            $business,
            $creditNoteId,
            (string) $profile->provider,
            (string) $profile->environment,
            $submission->payloadHash,
        );

        $result = $this->gateway->registerInvoice($submission, $profile);

        if (! $result->successful) {
            $failed = $this->attempts->fail(
                $business,
                $attempt->id,
                (string) ($result->errorCode ?? 'CORRECTIVE_FISCALIZATION_FAILED'),
                (string) ($result->errorMessage ?? 'Corrective fiscalization failed.'),
                $result->retryable,
                $result->httpStatus,
            );

            return [
                'credit_note' => null,
                'attempt' => $failed,
                'status' => $result->retryable ? 'retry_pending' : 'failed',
            ];
        }

        $verificationUrl = $result->verificationUrl ?: $this->qr->generate($submission);
        $credit = $this->attempts->succeed(
            $business,
            $attempt->id,
            $submission->iic,
            (string) $result->fic,
            $verificationUrl,
            $result->qrPayload ?: $verificationUrl,
            $result->requestId,
        );

        DB::table('fiscalization_profiles')
            ->where('business_id', $business->id)
            ->where('id', $profile->id)
            ->update([
                'status' => 'active',
                'last_verified_at' => now(),
                'updated_at' => now(),
            ]);

        return [
            'credit_note' => $credit,
            'attempt' => DB::table('credit_note_fiscalization_attempts')->where('id', $attempt->id)->first(),
            'status' => 'fiscalized',
        ];
    }
}
