<?php

namespace App\Services\Fiscalization;

use DOMDocument;
use DOMElement;
use RuntimeException;

final class FiscalXmlSigner
{
    private const DS = 'http://www.w3.org/2000/09/xmldsig#';
    private const EXC_C14N = 'http://www.w3.org/2001/10/xml-exc-c14n#';
    private const ENVELOPED = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';
    private const RSA_SHA256 = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';
    private const SHA256 = 'http://www.w3.org/2001/04/xmlenc#sha256';

    public function sign(DOMDocument $document, DOMElement $request, string $privateKeyPem, string $certificatePem, string $expectedId = 'Request'): string
    {
        if ($request->getAttribute('Id') !== $expectedId) {
            throw new RuntimeException('Fiscal XML signed element has an unexpected Id.');
        }

        $canonicalRequest = $request->C14N(true, false);
        if ($canonicalRequest === false) {
            throw new RuntimeException('Fiscal request canonicalization failed.');
        }

        $digestValue = base64_encode(hash('sha256', $canonicalRequest, true));

        $signature = $document->createElementNS(self::DS, 'Signature');
        $signedInfo = $document->createElementNS(self::DS, 'SignedInfo');

        $canonicalizationMethod = $document->createElementNS(self::DS, 'CanonicalizationMethod');
        $canonicalizationMethod->setAttribute('Algorithm', self::EXC_C14N);
        $signedInfo->appendChild($canonicalizationMethod);

        $signatureMethod = $document->createElementNS(self::DS, 'SignatureMethod');
        $signatureMethod->setAttribute('Algorithm', self::RSA_SHA256);
        $signedInfo->appendChild($signatureMethod);

        $reference = $document->createElementNS(self::DS, 'Reference');
        $reference->setAttribute('URI', '#'.$expectedId);

        $transforms = $document->createElementNS(self::DS, 'Transforms');
        $transformEnveloped = $document->createElementNS(self::DS, 'Transform');
        $transformEnveloped->setAttribute('Algorithm', self::ENVELOPED);
        $transforms->appendChild($transformEnveloped);
        $transformCanonical = $document->createElementNS(self::DS, 'Transform');
        $transformCanonical->setAttribute('Algorithm', self::EXC_C14N);
        $transforms->appendChild($transformCanonical);
        $reference->appendChild($transforms);

        $digestMethod = $document->createElementNS(self::DS, 'DigestMethod');
        $digestMethod->setAttribute('Algorithm', self::SHA256);
        $reference->appendChild($digestMethod);
        $reference->appendChild($document->createElementNS(self::DS, 'DigestValue', $digestValue));
        $signedInfo->appendChild($reference);

        $signature->appendChild($signedInfo);
        $request->appendChild($signature);

        $canonicalSignedInfo = $signedInfo->C14N(true, false);
        if ($canonicalSignedInfo === false) {
            throw new RuntimeException('Fiscal SignedInfo canonicalization failed.');
        }

        $privateKey = openssl_pkey_get_private($privateKeyPem);
        if ($privateKey === false) {
            throw new RuntimeException('Fiscal private key could not be loaded for XML signing.');
        }

        $signatureBytes = '';
        if (! openssl_sign($canonicalSignedInfo, $signatureBytes, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Fiscal XML signature generation failed.');
        }

        $signatureValue = $document->createElementNS(self::DS, 'SignatureValue', base64_encode($signatureBytes));
        $signature->appendChild($signatureValue);

        $keyInfo = $document->createElementNS(self::DS, 'KeyInfo');
        $x509Data = $document->createElementNS(self::DS, 'X509Data');
        $x509Data->appendChild($document->createElementNS(self::DS, 'X509Certificate', $this->certificateBody($certificatePem)));
        $keyInfo->appendChild($x509Data);
        $signature->appendChild($keyInfo);

        $xml = $document->saveXML();
        if ($xml === false) {
            throw new RuntimeException('Signed fiscal XML serialization failed.');
        }

        return $xml;
    }

    private function certificateBody(string $certificatePem): string
    {
        $body = preg_replace('/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s+/', '', $certificatePem);

        if (! is_string($body) || $body === '') {
            throw new RuntimeException('Fiscal X509 certificate is invalid.');
        }

        return $body;
    }
}
