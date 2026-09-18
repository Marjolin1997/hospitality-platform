<?php

namespace App\Services\Fiscalization;

use DOMDocument;
use DOMXPath;
use RuntimeException;

final class DptRegisterInvoiceResponseParser
{
    /** @return array{fic:string,request_uuid:?string,response_uuid:?string} */
    public function parse(string $xml): array
    {
        $document = new DOMDocument();
        $document->preserveWhiteSpace = false;

        if (! @$document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
            throw new RuntimeException('DPT response is not valid XML.');
        }

        $xpath = new DOMXPath($document);
        $fault = $xpath->query('//*[local-name()="Fault"]')->item(0);
        if ($fault) {
            $message = trim((string) ($xpath->query('.//*[local-name()="faultstring"]', $fault)->item(0)?->textContent ?? 'DPT SOAP fault.'));
            throw new RuntimeException($message !== '' ? $message : 'DPT SOAP fault.');
        }

        $fic = trim((string) ($xpath->query('//*[local-name()="RegisterInvoiceResponse"]/*[local-name()="FIC"]')->item(0)?->textContent ?? ''));
        if ($fic === '') {
            $errorCode = trim((string) ($xpath->query('//*[local-name()="Error"]/@Code')->item(0)?->nodeValue ?? 'DPT_RESPONSE_INVALID'));
            $errorMessage = trim((string) ($xpath->query('//*[local-name()="Error"]')->item(0)?->textContent ?? 'DPT response did not contain a FIC/NIVF.'));
            throw new RuntimeException($errorCode.': '.$errorMessage);
        }

        $header = $xpath->query('//*[local-name()="RegisterInvoiceResponse"]/*[local-name()="Header"]')->item(0);

        return [
            'fic' => $fic,
            'request_uuid' => $header?->attributes?->getNamedItem('RequestUUID')?->nodeValue,
            'response_uuid' => $header?->attributes?->getNamedItem('UUID')?->nodeValue,
        ];
    }
}
