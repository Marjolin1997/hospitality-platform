<?php

namespace App\Services\Fiscalization;

use RuntimeException;

final class Pkcs12CredentialLoader
{
    public function __construct(private readonly FiscalSecretResolver $secrets) {}

    /**
     * @return array{private_key_pem:string,certificate_pem:string}
     */
    public function load(string $certificateReference, ?string $passwordReference): array
    {
        $raw = $this->secrets->resolve($certificateReference);
        $password = $passwordReference ? $this->secrets->resolve($passwordReference) : '';

        $pkcs12 = $this->decodeIfBase64($raw);
        $certificates = [];

        if (! openssl_pkcs12_read($pkcs12, $certificates, $password)) {
            throw new RuntimeException('Fiscal PKCS#12 credentials could not be opened with the configured password.');
        }

        $privateKey = $certificates['pkey'] ?? null;
        $certificate = $certificates['cert'] ?? null;

        if (! is_string($privateKey) || $privateKey === '' || ! is_string($certificate) || $certificate === '') {
            throw new RuntimeException('Fiscal PKCS#12 credentials do not contain both a private key and certificate.');
        }

        return ['private_key_pem' => $privateKey, 'certificate_pem' => $certificate];
    }

    private function decodeIfBase64(string $value): string
    {
        if (str_contains($value, 'BEGIN')) {
            return $value;
        }

        $trimmed = trim($value);
        if ($trimmed === '' || strlen($trimmed) % 4 !== 0 || ! preg_match('/^[A-Za-z0-9+\/=\r\n]+$/', $trimmed)) {
            return $value;
        }

        $decoded = base64_decode($trimmed, true);

        return $decoded !== false ? $decoded : $value;
    }
}
