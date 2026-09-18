<?php

namespace App\Services\Fiscalization;

use Carbon\CarbonImmutable;
use RuntimeException;

final class FiscalCertificateInspector
{
    public function __construct(private readonly Pkcs12CredentialLoader $credentials) {}

    public function inspect(string $certificateReference, ?string $passwordReference): array
    {
        $loaded = $this->credentials->load($certificateReference, $passwordReference);

        return $this->inspectPem($loaded['certificate_pem'], $loaded['private_key_pem']);
    }

    /**
     * @return array{
     *   valid_now:bool,not_before:string,not_after:string,days_remaining:int,
     *   fingerprint_sha256:string,subject_cn:?string,issuer_cn:?string,private_key_matches:bool
     * }
     */
    public function inspectPem(string $certificatePem, string $privateKeyPem): array
    {
        $parsed = openssl_x509_parse($certificatePem);
        if (! is_array($parsed)) {
            throw new RuntimeException('Fiscal X509 certificate could not be parsed.');
        }

        $notBeforeTs = (int) ($parsed['validFrom_time_t'] ?? 0);
        $notAfterTs = (int) ($parsed['validTo_time_t'] ?? 0);
        if ($notBeforeTs <= 0 || $notAfterTs <= 0) {
            throw new RuntimeException('Fiscal certificate validity dates are missing.');
        }

        $notBefore = CarbonImmutable::createFromTimestampUTC($notBeforeTs);
        $notAfter = CarbonImmutable::createFromTimestampUTC($notAfterTs);
        $now = CarbonImmutable::now('UTC');

        $fingerprint = openssl_x509_fingerprint($certificatePem, 'sha256');
        if (! is_string($fingerprint) || $fingerprint === '') {
            throw new RuntimeException('Fiscal certificate SHA-256 fingerprint could not be generated.');
        }

        $validNow = $now->addMinutes(5)->greaterThanOrEqualTo($notBefore)
            && $now->lessThanOrEqualTo($notAfter);
        $secondsRemaining = $notAfter->getTimestamp() - $now->getTimestamp();

        return [
            'valid_now' => $validNow,
            'not_before' => $notBefore->toISOString(),
            'not_after' => $notAfter->toISOString(),
            'days_remaining' => max(0, (int) floor($secondsRemaining / 86400)),
            'fingerprint_sha256' => strtoupper(str_replace(':', '', $fingerprint)),
            'subject_cn' => isset($parsed['subject']['CN']) ? (string) $parsed['subject']['CN'] : null,
            'issuer_cn' => isset($parsed['issuer']['CN']) ? (string) $parsed['issuer']['CN'] : null,
            'private_key_matches' => openssl_x509_check_private_key($certificatePem, $privateKeyPem) === true,
        ];
    }
}
