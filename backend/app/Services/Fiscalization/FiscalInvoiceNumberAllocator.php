<?php

namespace App\Services\Fiscalization;

use App\Models\Business;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FiscalInvoiceNumberAllocator
{
    /**
     * @return array{ordinal:int,number:string,scope:string,year:int}
     */
    public function next(Business $business, string $invoiceType, ?string $tcrCode, CarbonImmutable $issuedAt): array
    {
        $invoiceType = strtoupper(trim($invoiceType));
        if (! in_array($invoiceType, ['CASH','NONCASH'], true)) {
            throw ValidationException::withMessages(['fiscal_invoice_type' => 'Unsupported fiscal invoice type.']);
        }

        if ($invoiceType === 'CASH' && ! $tcrCode) {
            throw ValidationException::withMessages(['fiscal_tcr_code' => 'Cash fiscal invoices require a TCR code.']);
        }

        $year = (int) $issuedAt->setTimezone($business->timezone)->format('Y');
        $scope = $invoiceType === 'CASH' ? (string) $tcrCode : 'NONCASH';

        DB::statement(
            'INSERT INTO business_fiscal_invoice_counters (business_id, fiscal_year, scope_key, last_number, created_at, updated_at)
             VALUES (?, ?, ?, LAST_INSERT_ID(1), UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1), updated_at = UTC_TIMESTAMP()',
            [$business->id, $year, $scope],
        );

        $ordinal = (int) DB::selectOne('SELECT LAST_INSERT_ID() AS sequence')->sequence;
        $number = $invoiceType === 'CASH'
            ? sprintf('%d/%d/%s', $ordinal, $year, $tcrCode)
            : sprintf('%d/%d', $ordinal, $year);

        return ['ordinal' => $ordinal, 'number' => $number, 'scope' => $scope, 'year' => $year];
    }
}
