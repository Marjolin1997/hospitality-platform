<?php

namespace App\Services\Fiscalization;

use App\Services\Fiscalization\Data\FiscalInvoiceSubmission;

final class QrVerificationUrlGenerator
{
    public function generate(FiscalInvoiceSubmission $submission): ?string
    {
        $base = $submission->environment === 'production'
            ? 'https://efiskalizimi-app.tatime.gov.al/invoice-check/#/verify'
            : env('FISCAL_VERIFY_URL_TEST');

        if (! is_string($base) || trim($base) === '') {
            return null;
        }

        $params = [
            'iic' => $submission->iic,
            'tin' => $submission->issuerNuis,
            'crtd' => $submission->issueDateTime,
            'ord' => $submission->invoiceOrdinal,
            'bu' => $submission->businessUnitCode,
            'cr' => $submission->tcrCode,
            'sw' => $submission->softwareCode,
            'prc' => $submission->totalPrice,
        ];

        $params = array_filter($params, static fn ($value): bool => $value !== null && $value !== '');

        return rtrim($base, '?').'?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}
