<?php

namespace App\Services\Fiscalization;

use App\Models\Business;
use App\Models\FiscalizationProfile;
use App\Models\Invoice;
use App\Services\Fiscalization\Data\FiscalInvoiceSubmission;
use App\Services\Fiscalization\Data\PreparedFiscalInvoice;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class FiscalInvoiceSubmissionFactory
{
    private const MONEY_SCALE = 2;

    public function __construct(
        private readonly FiscalInvoiceNumberAllocator $numbers,
        private readonly Pkcs12CredentialLoader $credentials,
        private readonly IicGenerator $iic,
        private readonly FiscalPaymentMapper $payments,
    ) {}

    public function prepare(Business $business, string $invoiceId, bool $subsequentDelivery = false): PreparedFiscalInvoice
    {
        return DB::transaction(function () use ($business, $invoiceId, $subsequentDelivery): PreparedFiscalInvoice {
            $invoice = Invoice::query()->forBusiness($business)->whereKey($invoiceId)->lockForUpdate()->first();
            abort_unless($invoice, 404);

            if ($invoice->status !== 'issued') {
                throw ValidationException::withMessages(['invoice' => 'Only issued invoices can be fiscalized.']);
            }
            if ($invoice->fiscalization_status === 'fiscalized' || filled($invoice->nivf)) {
                throw ValidationException::withMessages(['invoice' => 'This invoice is already fiscalized.']);
            }
            if ($invoice->currency !== 'ALL') {
                throw ValidationException::withMessages([
                    'currency' => 'Direct DPT fiscalization currently requires an ALL invoice snapshot until foreign-currency exchange fields are implemented.',
                ]);
            }
            if (! in_array($invoice->fiscal_invoice_type, ['CASH','NONCASH'], true)) {
                throw ValidationException::withMessages([
                    'fiscal_invoice_type' => 'The payment mix cannot be represented by one Albanian CASH/NONCASH fiscal invoice.',
                ]);
            }

            $profile = FiscalizationProfile::query()->forBusiness($business)->where('business_id', $business->id)->lockForUpdate()->first();
            if (! $profile || ! in_array($profile->status, ['configured','active'], true)) {
                throw ValidationException::withMessages(['fiscalization' => 'Fiscalization profile is not configured.']);
            }
            if ($profile->environment === 'production'
                && ($profile->status !== 'active' || $profile->production_activated_at === null)) {
                throw ValidationException::withMessages([
                    'fiscalization' => 'Production fiscalization remains locked until explicit production activation succeeds after TEST verification and preflight.',
                ]);
            }

            if (! is_string($business->tax_number) || ! preg_match('/^[A-Za-z][0-9]{8}[A-Za-z]$/', $business->tax_number)) {
                throw ValidationException::withMessages([
                    'fiscalization' => 'Business NUIS/NIPT must match the Albanian fiscal identity format.',
                ]);
            }

            if (filled($invoice->customer_tax_number)) {
                if (! is_string($invoice->customer_tax_number) || ! preg_match('/^[A-Za-z][0-9]{8}[A-Za-z]$/', $invoice->customer_tax_number)) {
                    throw ValidationException::withMessages([
                        'customer_tax_number' => 'Buyer NUIS/NIPT must match the Albanian fiscal identity format.',
                    ]);
                }
                if (! filled($invoice->customer_name)) {
                    throw ValidationException::withMessages([
                        'customer_name' => 'Buyer name is required when a buyer NUIS/NIPT is supplied.',
                    ]);
                }
            }

            foreach ([
                'business tax number' => $business->tax_number,
                'software code' => $profile->software_code,
                'certificate reference' => $profile->certificate_secret_ref,
                'business unit code' => $invoice->fiscal_business_unit_code_snapshot,
                'operator code' => $invoice->fiscal_operator_code_snapshot,
            ] as $label => $value) {
                if (! filled($value)) {
                    throw ValidationException::withMessages(['fiscalization' => ucfirst($label).' is required.']);
                }
            }
            if ($profile->is_issuer_in_vat === null) {
                throw ValidationException::withMessages(['fiscalization' => 'Issuer VAT registration must be explicitly configured.']);
            }
            if ($invoice->fiscal_invoice_type === 'CASH' && ! filled($invoice->fiscal_tcr_code_snapshot)) {
                throw ValidationException::withMessages(['fiscalization' => 'CASH fiscal invoices require a TCR code snapshot.']);
            }

            $issuedAt = CarbonImmutable::parse($invoice->issued_at)->setTimezone($business->timezone);
            $number = $this->ensureFiscalNumber($business, $invoice, $issuedAt);

            $credentials = $this->credentials->load(
                (string) $profile->certificate_secret_ref,
                $profile->certificate_password_secret_ref,
            );

            $issueDateTime = $issuedAt->format('Y-m-d\TH:i:sP');
            $sendDateTime = CarbonImmutable::now($business->timezone)->format('Y-m-d\TH:i:sP');
            $totalPrice = $this->money($invoice->grand_total);

            $iic = $this->iic->generate(
                issuerNuis: (string) $business->tax_number,
                issueDateTime: $issueDateTime,
                invoiceNumber: (string) $number['ordinal'],
                businessUnitCode: (string) $invoice->fiscal_business_unit_code_snapshot,
                tcrCode: (string) ($invoice->fiscal_tcr_code_snapshot ?? ''),
                softwareCode: (string) $profile->software_code,
                totalPrice: $totalPrice,
                privateKeyPem: $credentials['private_key_pem'],
            );

            $lineRows = DB::table('invoice_lines')
                ->where('business_id', $business->id)
                ->where('invoice_id', $invoice->id)
                ->orderBy('position')
                ->get();

            if ($lineRows->isEmpty()) {
                throw ValidationException::withMessages(['invoice' => 'Fiscal invoice has no immutable invoice lines.']);
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
                if (! $quantity->isEqualTo($quantity3)) {
                    throw ValidationException::withMessages([
                        'quantity' => 'Fiscal invoice item quantity must be representable with at most three decimals.',
                    ]);
                }
                if ($quantity3->isZero()) {
                    throw ValidationException::withMessages(['quantity' => 'Fiscal invoice item quantity cannot be zero.']);
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
                    'unit_price_before_vat' => $this->money($unitBeforeVat),
                    'unit_price_after_vat' => $this->money($unitAfterVat),
                    'price_before_vat' => $this->money($line->line_subtotal),
                    'vat_rate' => $this->money($rate),
                    'vat_amount' => $this->money($line->line_tax),
                    'price_after_vat' => $this->money($lineGross),
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
                'price_before_vat' => $this->money($group['base']),
                'vat_rate' => $this->money($group['rate']),
                'vat_amount' => $this->money($group['tax']),
            ], $taxGroups));

            $paymentRows = DB::table('invoice_payment_snapshots')
                ->where('business_id', $business->id)
                ->where('invoice_id', $invoice->id)
                ->orderBy('position')
                ->get();

            if ($paymentRows->isEmpty()) {
                throw ValidationException::withMessages(['payment' => 'Fiscal invoice has no immutable payment snapshots.']);
            }

            $fiscalPayments = [];
            $paymentTotal = BigDecimal::zero();
            foreach ($paymentRows as $payment) {
                $mapping = $this->payments->map((string) $payment->method);
                if ($mapping['invoice_type'] !== $invoice->fiscal_invoice_type) {
                    throw ValidationException::withMessages([
                        'payment' => 'Invoice payment methods cross CASH/NONCASH fiscal families.',
                    ]);
                }
                $amount = BigDecimal::of((string) $payment->amount_base);
                $paymentTotal = $paymentTotal->plus($amount);
                $fiscalPayments[] = ['type'=>$mapping['code'],'amount'=>$this->money($amount)];
            }

            if (! $paymentTotal->toScale(self::MONEY_SCALE, RoundingMode::HALF_UP)
                ->isEqualTo(BigDecimal::of($totalPrice))) {
                throw ValidationException::withMessages([
                    'payment' => 'Fiscal payment snapshots do not reconcile with the invoice total.',
                ]);
            }

            $seller = [
                'id_type' => 'NUIS',
                'id_num' => (string) $business->tax_number,
                'name' => (string) ($invoice->business_legal_name_snapshot ?: $invoice->business_name_snapshot ?: $business->name),
                'address' => $invoice->location_address_snapshot,
                'country' => 'ALB',
            ];

            $buyer = [];
            if (filled($invoice->customer_name) || filled($invoice->customer_tax_number)) {
                $buyer = [
                    'id_type' => filled($invoice->customer_tax_number) ? 'NUIS' : null,
                    'id_num' => $invoice->customer_tax_number,
                    'name' => $invoice->customer_name,
                    'country' => 'ALB',
                ];
            }

            $base = [
                'invoiceId' => (string) $invoice->id,
                'businessId' => (string) $business->id,
                'environment' => (string) $profile->environment,
                'requestUuid' => (string) Str::uuid(),
                'sendDateTime' => $sendDateTime,
                'issueDateTime' => $issueDateTime,
                'subsequentDeliveryType' => $subsequentDelivery ? 'NOINTERNET' : null,
                'invoiceType' => (string) $invoice->fiscal_invoice_type,
                'invoiceNumber' => (string) $number['number'],
                'invoiceOrdinal' => (int) $number['ordinal'],
                'issuerNuis' => (string) $business->tax_number,
                'isIssuerInVat' => (bool) $profile->is_issuer_in_vat,
                'businessUnitCode' => (string) $invoice->fiscal_business_unit_code_snapshot,
                'tcrCode' => $invoice->fiscal_tcr_code_snapshot,
                'operatorCode' => (string) $invoice->fiscal_operator_code_snapshot,
                'softwareCode' => (string) $profile->software_code,
                'currency' => 'ALL',
                'totalWithoutVat' => $this->money($invoice->subtotal),
                'totalVat' => $this->money($invoice->tax_total),
                'totalPrice' => $totalPrice,
                'iic' => $iic['iic'],
                'iicSignature' => $iic['signature'],
                'seller' => $seller,
                'buyer' => $buyer,
                'items' => $items,
                'sameTaxes' => $sameTaxes,
                'payments' => $fiscalPayments,
            ];

            $payloadHash = hash('sha256', json_encode($base, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $invoice->forceFill([
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
                ),
                $profile,
            );
        }, attempts: 3);
    }

    private function ensureFiscalNumber(Business $business, Invoice $invoice, CarbonImmutable $issuedAt): array
    {
        if (filled($invoice->fiscal_invoice_number) && $invoice->fiscal_ordinal_number) {
            return [
                'ordinal' => (int) $invoice->fiscal_ordinal_number,
                'number' => (string) $invoice->fiscal_invoice_number,
            ];
        }

        return $this->numbers->next(
            $business,
            (string) $invoice->fiscal_invoice_type,
            $invoice->fiscal_tcr_code_snapshot,
            $issuedAt,
        );
    }

    private function money(string|int|float|BigDecimal $value): string
    {
        return (string) BigDecimal::of((string) $value)->toScale(self::MONEY_SCALE, RoundingMode::HALF_UP);
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
