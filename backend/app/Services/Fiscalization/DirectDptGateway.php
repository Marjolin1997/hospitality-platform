<?php

namespace App\Services\Fiscalization;

use App\Models\FiscalizationProfile;
use App\Services\Fiscalization\Contracts\FiscalizationGateway;
use App\Services\Fiscalization\Data\FiscalInvoiceSubmission;
use App\Services\Fiscalization\Data\FiscalizationGatewayResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class DirectDptGateway implements FiscalizationGateway
{
    private const SOAP_ACTION = 'https://eFiskalizimi.tatime.gov.al/FiscalizationService/RegisterInvoice';

    public function __construct(
        private readonly DptRegisterInvoiceXmlBuilder $builder,
        private readonly FiscalXmlSigner $signer,
        private readonly Pkcs12CredentialLoader $credentials,
        private readonly FiscalCertificateInspector $certificateInspector,
        private readonly DptRegisterInvoiceResponseParser $parser,
        private readonly FiscalXmlSignatureVerifier $responseVerifier,
    ) {}

    public function registerInvoice(FiscalInvoiceSubmission $submission, FiscalizationProfile $profile): FiscalizationGatewayResult
    {
        try {
            $approvedEndpoint = $profile->environment === 'production'
                ? config('fiscalization.production_endpoint')
                : config('fiscalization.test_endpoint');

            if ($profile->environment === 'production') {
                if (! is_string($approvedEndpoint) || $approvedEndpoint === '') {
                    return FiscalizationGatewayResult::failure(
                        errorCode: 'DPT_PRODUCTION_ENDPOINT_NOT_APPROVED',
                        errorMessage: 'Production DPT endpoint allowlist is not configured.',
                        retryable: false,
                    );
                }

                if (rtrim((string) $profile->endpoint, '/') !== rtrim($approvedEndpoint, '/')) {
                    return FiscalizationGatewayResult::failure(
                        errorCode: 'DPT_PRODUCTION_ENDPOINT_MISMATCH',
                        errorMessage: 'Configured production endpoint does not match the deployment-approved DPT endpoint.',
                        retryable: false,
                    );
                }
            } elseif (is_string($approvedEndpoint) && $approvedEndpoint !== ''
                && rtrim((string) $profile->endpoint, '/') !== rtrim($approvedEndpoint, '/')) {
                return FiscalizationGatewayResult::failure(
                    errorCode: 'DPT_TEST_ENDPOINT_MISMATCH',
                    errorMessage: 'Configured TEST endpoint does not match the deployment-approved DPT endpoint.',
                    retryable: false,
                );
            }

            $credentials = $this->credentials->load(
                (string) $profile->certificate_secret_ref,
                $profile->certificate_password_secret_ref,
            );

            $certificate = $this->certificateInspector->inspectPem(
                $credentials['certificate_pem'],
                $credentials['private_key_pem'],
            );

            if (! $certificate['private_key_matches'] || ! $certificate['valid_now']) {
                return FiscalizationGatewayResult::failure(
                    errorCode: 'FISCAL_CERTIFICATE_INVALID',
                    errorMessage: 'Fiscal certificate is expired, not valid yet, or does not match the configured private key.',
                    retryable: false,
                );
            }

            ['document' => $document, 'request' => $request] = $this->builder->build($submission);
            $xml = $this->signer->sign(
                $document,
                $request,
                $credentials['private_key_pem'],
                $credentials['certificate_pem'],
            );

            $response = Http::connectTimeout(5)
                ->timeout(25)
                ->withHeaders([
                    'SOAPAction' => self::SOAP_ACTION,
                    'Accept' => 'text/xml, application/xml',
                ])
                ->withBody($xml, 'text/xml; charset=utf-8')
                ->post((string) $profile->endpoint);

            $status = $response->status();
            $body = (string) $response->body();

            if (! $response->successful()) {
                return FiscalizationGatewayResult::failure(
                    errorCode: 'DPT_HTTP_'.$status,
                    errorMessage: $this->safeMessage($body !== '' ? $body : 'DPT request failed.'),
                    retryable: in_array($status, [408,425,429], true) || $status >= 500,
                    httpStatus: $status,
                );
            }

            $this->responseVerifier->verify(
                $body,
                'Response',
                requireTrustedCa: $profile->environment === 'production',
            );
            $parsed = $this->parser->parse($body);

            if ($parsed['request_uuid'] !== null && $parsed['request_uuid'] !== $submission->requestUuid) {
                return FiscalizationGatewayResult::failure(
                    errorCode: 'DPT_REQUEST_UUID_MISMATCH',
                    errorMessage: 'DPT response UUID does not match the submitted fiscal request.',
                    retryable: false,
                    httpStatus: $status,
                );
            }

            return FiscalizationGatewayResult::success(
                fic: $parsed['fic'],
                requestId: $parsed['response_uuid'],
                metadata: [
                    'request_uuid' => $submission->requestUuid,
                    'response_uuid' => $parsed['response_uuid'],
                    'http_status' => $status,
                ],
            );
        } catch (ConnectionException $e) {
            return FiscalizationGatewayResult::failure(
                errorCode: 'DPT_CONNECTION_FAILED',
                errorMessage: 'Could not connect to the DPT fiscalization service.',
                retryable: true,
            );
        } catch (RuntimeException $e) {
            return FiscalizationGatewayResult::failure(
                errorCode: 'DPT_RESPONSE_INVALID',
                errorMessage: $this->safeMessage($e->getMessage()),
                retryable: false,
            );
        } catch (Throwable $e) {
            report($e);

            return FiscalizationGatewayResult::failure(
                errorCode: 'DPT_GATEWAY_ERROR',
                errorMessage: 'Fiscalization gateway failed before a valid DPT response was accepted.',
                retryable: false,
            );
        }
    }

    private function safeMessage(string $message): string
    {
        $clean = trim(strip_tags($message));
        if ($clean === '') {
            return 'DPT fiscalization request failed.';
        }

        return mb_substr($clean, 0, 1000);
    }
}
