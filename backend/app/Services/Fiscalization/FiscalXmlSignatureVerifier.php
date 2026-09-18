<?php

namespace App\Services\Fiscalization;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

final class FiscalXmlSignatureVerifier
{
    private const DS = 'http://www.w3.org/2000/09/xmldsig#';

    public function verify(string $xml, string $expectedId = 'Response', bool $requireTrustedCa = false): void
    {
        $document = new DOMDocument();
        $document->preserveWhiteSpace = false;
        if (! @$document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
            throw new RuntimeException('Fiscal response XML is malformed.');
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('ds', self::DS);

        $signature = $xpath->query('//*[local-name()="Signature" and namespace-uri()="'.self::DS.'"]')->item(0);
        if (! $signature instanceof DOMElement) {
            throw new RuntimeException('Fiscal response does not contain an XML signature.');
        }

        $reference = $xpath->query('.//ds:Reference', $signature)->item(0);
        $digestValueNode = $xpath->query('.//ds:DigestValue', $signature)->item(0);
        $signedInfo = $xpath->query('./ds:SignedInfo', $signature)->item(0);
        $signatureValueNode = $xpath->query('./ds:SignatureValue', $signature)->item(0);
        $certificateNode = $xpath->query('.//ds:X509Certificate', $signature)->item(0);

        if (! $reference instanceof DOMElement || ! $signedInfo instanceof DOMElement || ! $digestValueNode || ! $signatureValueNode || ! $certificateNode) {
            throw new RuntimeException('Fiscal response XML signature is incomplete.');
        }

        if ($reference->getAttribute('URI') !== '#'.$expectedId) {
            throw new RuntimeException('Fiscal response XML signature references an unexpected element.');
        }

        $target = $xpath->query('//*[@Id="'.$expectedId.'"]')->item(0);
        if (! $target instanceof DOMElement) {
            throw new RuntimeException('Fiscal response signed element is missing.');
        }

        $targetClone = $target->cloneNode(true);
        if (! $targetClone instanceof DOMElement) {
            throw new RuntimeException('Fiscal response signed element could not be cloned.');
        }
        $cloneDoc = new DOMDocument();
        $cloneDoc->appendChild($cloneDoc->importNode($targetClone, true));
        $cloneXpath = new DOMXPath($cloneDoc);
        $cloneXpath->registerNamespace('ds', self::DS);
        foreach (iterator_to_array($cloneXpath->query('//*[local-name()="Signature" and namespace-uri()="'.self::DS.'"]')) as $signatureNode) {
            $signatureNode->parentNode?->removeChild($signatureNode);
        }

        $canonicalTarget = $cloneDoc->documentElement?->C14N(true, false);
        if ($canonicalTarget === false || $canonicalTarget === null) {
            throw new RuntimeException('Fiscal response digest canonicalization failed.');
        }

        $expectedDigest = base64_encode(hash('sha256', $canonicalTarget, true));
        if (! hash_equals(trim((string) $digestValueNode->textContent), $expectedDigest)) {
            throw new RuntimeException('Fiscal response digest validation failed.');
        }

        $certificateBody = preg_replace('/\s+/', '', (string) $certificateNode->textContent);
        if (! is_string($certificateBody) || $certificateBody === '') {
            throw new RuntimeException('Fiscal response signing certificate is missing.');
        }
        $certificatePem = "-----BEGIN CERTIFICATE-----\n".chunk_split($certificateBody, 64, "\n")."-----END CERTIFICATE-----\n";
        $certificateInfo = openssl_x509_parse($certificatePem);
        if (! is_array($certificateInfo)) {
            throw new RuntimeException('Fiscal response X509 certificate cannot be parsed.');
        }

        $commonName = (string) ($certificateInfo['subject']['CN'] ?? '');
        if (! str_contains($commonName, 'GDT eFiskalizimi')) {
            throw new RuntimeException('Fiscal response certificate subject is not the expected GDT eFiskalizimi identity.');
        }

        $caBundle = config('fiscalization.dpt_ca_bundle');
        if ($requireTrustedCa) {
            if (! is_string($caBundle) || $caBundle === '' || ! is_readable($caBundle)) {
                throw new RuntimeException('Production fiscalization requires a configured DPT/AKSHI CA bundle.');
            }

            $purpose = openssl_x509_checkpurpose($certificatePem, X509_PURPOSE_ANY, [$caBundle]);
            if ($purpose !== true && $purpose !== 1) {
                throw new RuntimeException('Fiscal response certificate trust validation failed.');
            }
        }

        $canonicalSignedInfo = $signedInfo->C14N(true, false);
        if ($canonicalSignedInfo === false) {
            throw new RuntimeException('Fiscal response SignedInfo canonicalization failed.');
        }

        $signatureBytes = base64_decode(trim((string) $signatureValueNode->textContent), true);
        if ($signatureBytes === false) {
            throw new RuntimeException('Fiscal response signature value is not valid base64.');
        }

        $publicKey = openssl_pkey_get_public($certificatePem);
        if ($publicKey === false) {
            throw new RuntimeException('Fiscal response public key cannot be loaded.');
        }

        $verified = openssl_verify($canonicalSignedInfo, $signatureBytes, $publicKey, OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            throw new RuntimeException('Fiscal response signature verification failed.');
        }
    }
}
