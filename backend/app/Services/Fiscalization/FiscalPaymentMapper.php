<?php

namespace App\Services\Fiscalization;

use InvalidArgumentException;

final class FiscalPaymentMapper
{
    /** @return array{code:string,invoice_type:string,label:string} */
    public function map(string $internalMethod): array
    {
        return match ($internalMethod) {
            'cash' => ['code' => 'BANKNOTE', 'invoice_type' => 'CASH', 'label' => 'Kartëmonedha dhe monedha'],
            'card' => ['code' => 'CARD', 'invoice_type' => 'CASH', 'label' => 'Kartë krediti/debiti'],
            'bank_transfer' => ['code' => 'ACCOUNT', 'invoice_type' => 'NONCASH', 'label' => 'Llogari transaksioni'],
            'other' => ['code' => 'OTHER', 'invoice_type' => 'NONCASH', 'label' => 'Pagesë tjetër pa para në dorë'],
            default => throw new InvalidArgumentException("Unsupported payment method '{$internalMethod}' for fiscalization."),
        };
    }

    /** @param iterable<int, object|array<string,mixed>> $payments */
    public function invoiceType(iterable $payments): ?string
    {
        $types = [];

        foreach ($payments as $payment) {
            $method = is_array($payment) ? ($payment['method'] ?? null) : ($payment->method ?? null);
            if (! is_string($method) || $method === '') {
                continue;
            }

            $types[$this->map($method)['invoice_type']] = true;
        }

        if ($types === []) {
            return null;
        }

        return count($types) === 1 ? array_key_first($types) : null;
    }

    /** @param iterable<int, object|array<string,mixed>> $payments */
    public function mixesCashAndNonCash(iterable $payments): bool
    {
        $types = [];

        foreach ($payments as $payment) {
            $method = is_array($payment) ? ($payment['method'] ?? null) : ($payment->method ?? null);
            if (! is_string($method) || $method === '') {
                continue;
            }

            $types[$this->map($method)['invoice_type']] = true;
        }

        return count($types) > 1;
    }
}
