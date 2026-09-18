<?php

namespace App\Services\Fiscalization;

use App\Models\Business;
use App\Models\FiscalizationProfile;
use App\Models\InvoiceCreditNote;
use App\Services\Fiscalization\Data\FiscalInvoiceSubmission;
use App\Services\Fiscalization\Data\PreparedFiscalInvoice;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class FiscalCreditNoteSubmissionFactory
{
    private const MONEY_SCALE = 2;

    public function __construct(
        private readonly FiscalInvoiceNumberAllocator $numbers,
        private readonly Pkcs12CredentialLoader $credentials,
        private readonly IicGenerator $iic,
        private readonly FiscalPaymentMapper $payments,
    ) {}

    public function prepare(Business $business, string $creditNoteId, bool $subsequentDelivery = false): PreparedFiscalInvoice
    {
        return DB::transaction(function () use ($business, $creditNoteId, $subsequentDelivery): PreparedFiscalInvoice {
            $credit = InvoiceCreditNote::query()
                ->forBusiness($business)
                ->whereKey($creditNoteId)
                ->lockForUpdate()
                ->first();
            abort_unless($credit, 404);

            if (! in_array($credit->status, ['issued','partially_refunded','refunded'], true)) {
                throw ValidationException::withMessages(['credit_note' => 'Only an issued corrective document can be fiscalized.']);
            }
            if ($credit->fiscalization_status === 'fiscalized' || filled($credit->nivf)) {
                throw ValidationException::withMessages(['credit_note' => 'This corrective document is already fiscalized.']);
            }
            if ($credit->currency !== 'ALL') {
                throw ValidationException::withMessages([
                    'currency' => 'Direct DPT corrective fiscalization currently requires an ALL document snapshot.',
                ]);
            }
            if (! in_array($credit->fiscal_invoice_type, ['CASH','NONCASH'], true)) {
                throw ValidationException::withMessages([
                    'fiscal_invoice_type' => 'The corrective document does not have a valid CASH/NONCASH fiscal family.',
                ]);
            }

            $original = DB::table('invoices')
                ->where('business_id', $business->id)
                ->where('id', $credit->invoice_id)
                ->lockForUpdate()
                ->first();
            abort_unless($original, 404);

            if ($original->fiscalization_status !== 'fiscalized' || ! filled($original->nslf) || ! filled($original->nivf)) {
                throw ValidationException::withMessages([
                    'credit_note' => 'The original invoice must be successfully fiscalized before its corrective document is submitted.',
                ]);
            }

            $profile = FiscalizationProfile::query()
                ->forBusiness($business)
                ->where('business_id', $business->id)
                ->lockForUpdate()
                ->first();

            if (! $profile || ! in_array($profile->status, ['configured','active'], true)) {
                throw ValidationException::withMessages(['fiscalization' => 'Fiscalization profile is not configured.']);
            }
            if ($profile->environment === 'production'
                && ($profile->status !== 'active' || $profile->production_activated_at === null)) {
                throw ValidationException::withMessages([
                    'fiscalization' => 'Production fiscalization remains locked until explicit production activation succeeds after TEST verification and preflight.',
                ]);
            }
            if ($profile->is_issuer_in_vat === null) {
                throw ValidationException::withMessages(['fiscalization' => 'Issuer VAT registration must be explicitly configured.']);
            }

            foreach ([
                'business tax number' => $business->tax_number,
                'software code' => $profile->software_code,
                'certificate reference' => $profile->certificate_secret_ref,
                'business unit code' => $credit->fiscal_business_unit_code_snapshot,
                'operator code' => $credit->fiscal_operator_code_snapshot,
                'original invoice NSLF/IIC' => $credit->original_invoice_nslf_snapshot,
                'original invoice issue date-time' => $credit->original_invoice_issued_at_snapshot,
            ] as $label => $value) {
                if (! filled($value)) {
                    throw ValidationException::withMessages(['fiscalization' => ucfirst($label).' is required.']);
                }
            }

            if (! is_string($business->tax_number) || ! preg_match('/^[A-Za-z][0-9]{8}[A-Za-z]$/', $business->tax_number)) {
                throw ValidationException::withMessages([
                    'fiscalization' => 'Business NUIS/NIPT must match the Albanian fiscal identity format.',
                ]);
            }
            if (filled($credit->customer_tax_number_snapshot)
                && (! is_string($credit->customer_tax_number_snapshot)
                    || ! preg_match('/^[A-Za-z][0-9]{8}[A-Za-z]$/', $credit->customer_tax_number_snapshot))) {
                throw ValidationException::withMessages([
                    'customer_tax_number' => 'Buyer NUIS/NIPT must match the Albanian fiscal identity format.',
                ]);
            }
            if (filled($credit->customer_tax_number_snapshot) && ! filled($credit->customer_name_snapshot)) {
                throw ValidationException::withMessages([
                    'customer_name' => 'Buyer name is required when a buyer NUIS/NIPT is supplied.',
                ]);
            }
            if ($credit->fiscal_invoice_type === 'CASH' && ! filled($credit->fiscal_tcr_code_snapshot)) {
                throw ValidationException::withMessages([
                    'fiscalization' => 'CASH corrective documents require a TCR code snapshot.',
                ]);
            }

            $issuedAt = CarbonImmutable::parse($credit->issued_at)->setTimezone($business->timezone);
            $number = $this->ensureFiscalNumber($business, $credit, $issuedAt);

            $credentials = $this->credentials->load(
                (string) $profile->certificate_secret_ref,
                $profile->certificate_password_secret_ref,
            );

            $issueDateTime = $issuedAt->format('Y-m-d\TH:i:sP');
            $originalIssueDateTime = CarbonImmutable::parse($credit->original_invoice_issued_at_snapshot)
                ->setTimezone($business->timezone)
                ->format('Y-m-d\TH:i:sP');
            $sendDateTime = CarbonImmutable::now($business->timezone)->format('Y-m-d\TH:i:sP');

            $totalPrice = $this->negativeMoney($credit->grand_total);
            $iic = $this->iic->generate(
                issuerNuis: (string) $business->tax_number,
                issueDateTime: $issueDateTime,
                invoiceNumber: (string) $number['ordinal'],
                businessUnitCode: (string) $credit->fiscal_business_unit_code_snapshot,
                tcrCode: (string) ($credit->fiscal_tcr_code_snapshot ?? ''),
                softwareCode: (string) $profile->software_code,
                totalPrice: $totalPrice,
                privateKeyPem: $credentials['private_key_pem'],
            );

            $lineRows = DB::table('invoice_credit_note_lines')
                ->where('business_id', $business->id)
                ->where('invoice_credit_note_id', $credit->id)
                ->orderBy('position')
                ->get();

            if ($lineRows->isEmpty()) {
                throw ValidationException::withMessages(['credit_note' => 'Corrective document has no immutable lines.']);
            }

            $items = [];
            $taxGroups = [];
            foreach ($lineRows as $line) {
                $rate = BigDecimal::of((string) $line->tax_rate);
                $rateKey = (string) $rate->toScale(2, RoundingMode::HALF_UP);
                if (! in_array($rateKey, ['0.00','6.00','10.00','20.00'], true)) {
                    throw ValidationException::withMessages([
                        'tax_rate' => "VAT rate {$rateKey}% is not in the supported Albanian fiscal rate set.",
                    ]);
                }

                $quantity = BigDecimal::of((string) $line->quantity);
                $quantity3 = $quantity->toScale(3, RoundingMode::HALF_UP);
                if (! $quantity->isEqualTo($quantity3) || $quantity3->isZero()) {
                    throw ValidationException::withMessages([
                        'quantity' => 'Corrective fiscal quantity must be non-zero and use at most three decimals.',
                    ]);
                }

                $grossUnitBeforeDiscount = BigDecimal::of((string) $line->unit_price);
                $taxFactor = BigDecimal::of('1')->plus($rate->dividedBy('100', 10, RoundingMode::HALF_UP));
                $unitBeforeVat = $grossUnitBeforeDiscount->dividedBy($taxFactor, 10, RoundingMode::HALF_UP);

                $lineGross = BigDecimal::of((string) $line->line_total);
                $unitAfterVat = $lineGross->dividedBy($quantity3, 10, RoundingMode::HALF_UP);
                $rebate = BigDecimal::of((string) $line->discount_percent);

                $item = [
                    'name' => (string) $line->product_name_snapshot,
                    'code' => $line->sku_snapshot ?: null,
                    'unit' => (string) ($line->unit_label_snapshot ?: 'Copë'),
                    'quantity' => $this->quantity($quantity3),
                    'unit_price_before_vat' => $this->negativeMoney($unitBeforeVat),
                    'unit_price_after_vat' => $this->negativeMoney($unitAfterVat),
                    'price_before_vat' => $this->negativeMoney($line->line_subtotal),
                    'vat_rate' => $this->money($rate),
                    'vat_amount' => $this->negativeMoney($line->line_tax),
                    'price_after_vat' => $this->negativeMoney($lineGross),
                ];

                if (! $rebate->isZero()) {
                    $item['rebate_percent'] = $this->percent($rebate);
                    $item['rebate_reduces_base'] = true;
                }

                $items[] = $item;
                $taxGroups[$rateKey] ??= ['count'=>0,'base'=>BigDecimal::zero(),'tax'=>BigDecimal::zero(),'rate'=>$rate];
                $taxGroups[$rateKey]['count']++;
                $taxGroups[$rateKey]['base'] = $taxGroups[$rateKey]['base']->plus(BigDecimal::of((string) $line->line_subtotal));
                $taxGroups[$rateKey]['tax'] = $taxGroups[$rateKey]['tax']->plus(BigDecimal::of((string) $line->line_tax));
            }

            $sameTaxes = array_values(array_map(fn (array $group): array => [
                'count' => $group['count'],
                'price_before_vat' => $this->negativeMoney($group['base']),
                'vat_rate' => $this->money($group['rate']),
                'vat_amount' => $this->negativeMoney($group['tax']),
            ], $taxGroups));

            $paymentRows = DB::table('invoice_payment_snapshots')
                ->where('business_id', $business->id)
                ->where('invoice_id', $original->id)
                ->orderBy('position')
                ->get();

            if ($paymentRows->isEmpty()) {
                throw ValidationException::withMessages([
                    'payment' => 'Original invoice has no immutable payment snapshots for corrective fiscalization.',
                ]);
            }

            $fiscalPayments = [];
            $paymentTotal = BigDecimal::zero();
            foreach ($paymentRows as $payment) {
                $mapping = $this->payments->map((string) $payment->method);
                if ($mapping['invoice_type'] !== $credit->fiscal_invoice_type) {
                    throw ValidationException::withMessages([
                        'payment' => 'Corrective payment methods cross CASH/NONCASH fiscal families.',
                    ]);
                }

                $amount = BigDecimal::of((string) $payment->amount_base);
                $paymentTotal = $paymentTotal->plus($amount);
                $fiscalPayments[] = [
                    'type' => $mapping['code'],
                    'amount' => $this->negativeMoney($amount),
                ];
            }

            if (! $paymentTotal->toScale(self::MONEY_SCALE, RoundingMode::HALF_UP)
                ->isEqualTo(BigDecimal::of((string) $credit->grand_total)->toScale(self::MONEY_SCALE, RoundingMode::HALF_UP))) {
                throw ValidationException::withMessages([
                    'payment' => 'Corrective payment snapshots do not reconcile with the document total.',
                ]);
            }

            $seller = [
                'id_type' => 'NUIS',
                'id_num' => (string) $business->tax_number,
                'name' => (string) ($original->business_legal_name_snapshot ?: $original->business_name_snapshot ?: $business->name),
                'address' => $original->location_address_snapshot,
                'country' => 'ALB',
            ];

            $buyer = [];
            if (filled($credit->customer_name_snapshot) || filled($credit->customer_tax_number_snapshot)) {
                $buyer = [
                    'id_type' => filled($credit->customer_tax_number_snapshot) ? 'NUIS' : null,
                    'id_num' => $credit->customer_tax_number_snapshot,
                    'name' => $credit->customer_name_snapshot,
                    'country' => 'ALB',
                ];
            }

            $base = [
                'invoiceId' => (string) $credit->id,
                'businessId' => (string) $business->id,
                'environment' => (string) $profile->environment,
                'requestUuid' => (string) Str::uuid(),
                'sendDateTime' => $sendDateTime,
                'issueDateTime' => $issueDateTime,
                'subsequentDeliveryType' => $subsequentDelivery ? 'NOINTERNET' : null,
                'invoiceType' => (string) $credit->fiscal_invoice_type,
                'invoiceNumber' => (string) $number['number'],
                'invoiceOrdinal' => (int) $number['ordinal'],
                'issuerNuis' => (string) $business->tax_number,
                'isIssuerInVat' => (bool) $profile->is_issuer_in_vat,
                'businessUnitCode' => (string) $credit->fiscal_business_unit_code_snapshot,
                'tcrCode' => $credit->fiscal_tcr_code_snapshot,
                'operatorCode' => (string) $credit->fiscal_operator_code_snapshot,
                'softwareCode' => (string) $profile->software_code,
                'currency' => 'ALL',
                'totalWithoutVat' => $this->negativeMoney($credit->subtotal),
                'totalVat' => $this->negativeMoney($credit->tax_total),
                'totalPrice' => $totalPrice,
                'iic' => $iic['iic'],
                'iicSignature' => $iic['signature'],
                'seller' => $seller,
                'buyer' => $buyer,
                'items' => $items,
                'sameTaxes' => $sameTaxes,
                'payments' => $fiscalPayments,
                'correctiveIicRef' => (string) $credit->original_invoice_nslf_snapshot,
                'correctiveIssueDateTime' => $originalIssueDateTime,
            ];

            $payloadHash = hash('sha256', json_encode(
                $base,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ));

            $credit->forceFill([
                'fiscal_invoice_number' => $number['number'],
                'fiscal_ordinal_number' => $number['ordinal'],
                'nslf' => $iic['iic'],
            ])->save();

            return new PreparedFiscalInvoice(
                new FiscalInvoiceSubmission(
                    invoiceId: $base['invoiceId'],
                    businessId: $base['businessId'],
                    environment: $base['environment'],
                    requestUuid: $base['requestUuid'],
                    sendDateTime: $base['sendDateTime'],
                    issueDateTime: $base['issueDateTime'],
                    subsequentDeliveryType: $base['subsequentDeliveryType'],
                    invoiceType: $base['invoiceType'],
                    invoiceNumber: $base['invoiceNumber'],
                    invoiceOrdinal: $base['invoiceOrdinal'],
                    issuerNuis: $base['issuerNuis'],
                    isIssuerInVat: $base['isIssuerInVat'],
                    businessUnitCode: $base['businessUnitCode'],
                    tcrCode: $base['tcrCode'],
                    operatorCode: $base['operatorCode'],
                    softwareCode: $base['softwareCode'],
                    currency: $base['currency'],
                    totalWithoutVat: $base['totalWithoutVat'],
                    totalVat: $base['totalVat'],
                    totalPrice: $base['totalPrice'],
                    iic: $base['iic'],
                    iicSignature: $base['iicSignature'],
                    seller: $base['seller'],
                    buyer: $base['buyer'],
                    items: $base['items'],
                    sameTaxes: $base['sameTaxes'],
                    payments: $base['payments'],
                    payloadHash: $payloadHash,
                    correctiveIicRef: $base['correctiveIicRef'],
                    correctiveIssueDateTime: $base['correctiveIssueDateTime'],
                ),
                $profile,
            );
        }, attempts: 3);
    }

    private function ensureFiscalNumber(Business $business, InvoiceCreditNote $credit, CarbonImmutable $issuedAt): array
    {
        if (filled($credit->fiscal_invoice_number) && $credit->fiscal_ordinal_number) {
            return [
                'ordinal' => (int) $credit->fiscal_ordinal_number,
                'number' => (string) $credit->fiscal_invoice_number,
            ];
        }

        return $this->numbers->next(
            $business,
            (string) $credit->fiscal_invoice_type,
            $credit->fiscal_tcr_code_snapshot,
            $issuedAt,
        );
    }

    private function money(string|int|float|BigDecimal $value): string
    {
        return (string) BigDecimal::of((string) $value)->toScale(self::MONEY_SCALE, RoundingMode::HALF_UP);
    }

    private function negativeMoney(string|int|float|BigDecimal $value): string
    {
        $amount = BigDecimal::of((string) $value)->abs()->negated();

        return (string) $amount->toScale(self::MONEY_SCALE, RoundingMode::HALF_UP);
    }

    private function percent(BigDecimal $value): string
    {
        return (string) $value->toScale(4, RoundingMode::HALF_UP);
    }

    private function quantity(BigDecimal $value): string
    {
        $string = (string) $value->toScale(3, RoundingMode::HALF_UP);

        return rtrim(rtrim($string, '0'), '.');
    }
}
