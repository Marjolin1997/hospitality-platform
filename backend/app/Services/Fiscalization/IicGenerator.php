<?php

namespace App\Services\Fiscalization;

use InvalidArgumentException;
use RuntimeException;

final class IicGenerator
{
    /**
     * @return array{input:string,signature:string,iic:string}
     */
    public function generate(
        string $issuerNuis,
        string $issueDateTime,
        string $invoiceNumber,
        string $businessUnitCode,
        string $tcrCode,
        string $softwareCode,
        string $totalPrice,
        string $privateKeyPem,
    ): array {
        foreach ([
            'issuerNuis' => $issuerNuis,
            'issueDateTime' => $issueDateTime,
            'invoiceNumber' => $invoiceNumber,
            'businessUnitCode' => $businessUnitCode,
            'tcrCode' => $tcrCode,
            'softwareCode' => $softwareCode,
            'totalPrice' => $totalPrice,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("{$field} is required for NSLF generation.");
            }
        }

        $input = implode('|', [
            $issuerNuis,
            $issueDateTime,
            $invoiceNumber,
            $businessUnitCode,
            $tcrCode,
            $softwareCode,
            $totalPrice,
        ]);

        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false) {
            throw new RuntimeException('The fiscalization private key could not be loaded.');
        }

        $signature = '';
        if (! openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('NSLF signature generation failed.');
        }

        return [
            'input' => $input,
            'signature' => strtoupper(bin2hex($signature)),
            'iic' => strtoupper(md5($signature)),
        ];
    }
}
