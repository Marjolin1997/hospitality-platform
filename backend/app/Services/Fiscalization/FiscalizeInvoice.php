<?php

namespace App\Services\Fiscalization;

use App\Models\Business;
use App\Services\Fiscalization\Contracts\FiscalizationGateway;
use Illuminate\Support\Facades\DB;

final class FiscalizeInvoice
{
    public function __construct(
        private readonly FiscalInvoiceSubmissionFactory $factory,
        private readonly FiscalizationGateway $gateway,
        private readonly FiscalizationAttemptRecorder $attempts,
        private readonly QrVerificationUrlGenerator $qr,
        private readonly FiscalizationDispatchGuard $dispatchGuard,
    ) {}

    /**
     * @return array{invoice:object|null,attempt:object|null,status:string}
     */
    public function execute(Business $business, string $invoiceId, bool $subsequentDelivery = false): array
    {
        $this->dispatchGuard->assertCanDispatch($business);
        $prepared = $this->factory->prepare($business, $invoiceId, $subsequentDelivery);
        $submission = $prepared->submission;
        $profile = $prepared->profile;

        $attempt = $this->attempts->begin(
            $business,
            $invoiceId,
            (string) $profile->provider,
            (string) $profile->environment,
            $submission->payloadHash,
        );

        $result = $this->gateway->registerInvoice($submission, $profile);

        if (! $result->successful) {
            $failed = $this->attempts->fail(
                $business,
                $attempt->id,
                (string) ($result->errorCode ?? 'FISCALIZATION_FAILED'),
                (string) ($result->errorMessage ?? 'Fiscalization failed.'),
                $result->retryable,
                $result->httpStatus,
            );

            return [
                'invoice' => null,
                'attempt' => $failed,
                'status' => $result->retryable ? 'retry_pending' : 'failed',
            ];
        }

        $verificationUrl = $result->verificationUrl ?: $this->qr->generate($submission);
        $invoice = $this->attempts->succeed(
            $business,
            $attempt->id,
            $submission->iic,
            (string) $result->fic,
            $verificationUrl,
            $result->qrPayload ?: $verificationUrl,
            $result->requestId,
        );

        $verificationColumn = $profile->environment === 'production'
            ? 'last_production_verified_at'
            : 'last_test_verified_at';

        DB::table('fiscalization_profiles')
            ->where('business_id', $business->id)
            ->where('id', $profile->id)
            ->update([
                'status' => 'active',
                'last_verified_at' => now(),
                $verificationColumn => now(),
                'updated_at' => now(),
            ]);

        return [
            'invoice' => $invoice,
            'attempt' => DB::table('invoice_fiscalization_attempts')->where('id', $attempt->id)->first(),
            'status' => 'fiscalized',
        ];
    }
}
