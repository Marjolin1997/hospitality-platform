<?php

namespace App\Services\Invoicing;

use App\Models\Business;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use App\Services\Fiscalization\FiscalPaymentMapper;
use App\Services\Finance\CurrencyConverter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class IssueInvoice
{
    private const MONEY_SCALE = 4;

    public function __construct(
        private readonly FiscalPaymentMapper $paymentMapper,
        private readonly InvoiceLineAllocator $lineAllocator,
        private readonly CurrencyConverter $converter,
    ) {}

    public function execute(Business $business, User $user, array $payload): object
    {
        return DB::transaction(function () use ($business, $user, $payload): object {
            $order = DB::table('orders')
                ->where('business_id', $business->id)
                ->where('id', $payload['order_id'])
                ->lockForUpdate()
                ->first();

            abort_unless($order, 404);

            $existing = DB::table('invoices')
                ->where('business_id', $business->id)
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->assertReplay($existing, $payload);
                return $this->withLines($business, $existing);
            }

            if ($order->status !== 'paid') {
                throw ValidationException::withMessages([
                    'order_id' => 'Only fully paid orders can be invoiced.',
                ]);
            }

            $netPaid = $this->netPaidBase($business, (string) $order->id);
            $grandTotal = BigDecimal::of((string) $order->grand_total);
            if ($netPaid->isLessThan($grandTotal)) {
                throw ValidationException::withMessages([
                    'order_id' => 'The order is not fully settled after refunds.',
                ]);
            }

            $items = DB::table('order_items as oi')
                ->leftJoin('products as p', function ($join) use ($business): void {
                    $join->on('p.id', '=', 'oi.product_id')->where('p.business_id', $business->id);
                })
                ->where('oi.business_id', $business->id)
                ->where('oi.order_id', $order->id)
                ->whereNull('oi.voided_at')
                ->select('oi.*','p.unit_code as product_unit_code','p.unit_label as product_unit_label')
                ->orderBy('oi.created_at')
                ->orderBy('oi.id')
                ->get();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    'order_id' => 'An invoice requires at least one active order item.',
                ]);
            }

            $allocation = $this->lineAllocator->allocate($items, (string) $order->discount_total);
            if (! BigDecimal::of($allocation['grand_total'])->isEqualTo(BigDecimal::of((string) $order->grand_total))) {
                throw ValidationException::withMessages([
                    'order_id' => 'Fiscal invoice allocation does not reconcile with the authoritative order total.',
                ]);
            }

            $location = DB::table('locations')
                ->where('business_id', $business->id)
                ->where('id', $order->location_id)
                ->first();

            abort_unless($location, 422, 'The order location is no longer available.');

            $operatorCode = DB::table('business_user')
                ->where('business_id', $business->id)
                ->where('user_id', $user->id)
                ->value('fiscal_operator_code');

            $payments = DB::table('payments')
                ->where('business_id', $business->id)
                ->where('order_id', $order->id)
                ->where('status', 'completed')
                ->orderBy('paid_at')
                ->orderBy('id')
                ->get();

            $fiscalInvoiceType = $this->paymentMapper->invoiceType($payments);

            $tcrCodes = DB::table('payments as p')
                ->join('cash_sessions as cs', 'cs.id', '=', 'p.cash_session_id')
                ->join('cash_registers as cr', 'cr.id', '=', 'cs.cash_register_id')
                ->where('p.business_id', $business->id)
                ->where('p.order_id', $order->id)
                ->where('p.status', 'completed')
                ->whereNotNull('cr.fiscal_tcr_code')
                ->distinct()
                ->pluck('cr.fiscal_tcr_code');

            if ($fiscalInvoiceType === 'CASH' && $tcrCodes->count() > 1) {
                throw ValidationException::withMessages([
                    'cash_register_id' => 'A single fiscal invoice cannot be issued from multiple TCR devices.',
                ]);
            }

            $selectedRegister = null;
            if (! empty($payload['cash_register_id'])) {
                $selectedRegister = DB::table('cash_registers')
                    ->where('business_id', $business->id)
                    ->where('location_id', $order->location_id)
                    ->where('id', $payload['cash_register_id'])
                    ->where('is_active', true)
                    ->first();

                if (! $selectedRegister) {
                    throw ValidationException::withMessages([
                        'cash_register_id' => 'The selected fiscal register is not active in this order location.',
                    ]);
                }
                if (! filled($selectedRegister->fiscal_tcr_code)) {
                    throw ValidationException::withMessages([
                        'cash_register_id' => 'The selected register has no fiscal TCR code configured.',
                    ]);
                }
            }

            $fiscalTcrCode = $tcrCodes->count() === 1 ? (string) $tcrCodes->first() : null;

            if ($selectedRegister) {
                if ($fiscalTcrCode !== null && $fiscalTcrCode !== (string) $selectedRegister->fiscal_tcr_code) {
                    throw ValidationException::withMessages([
                        'cash_register_id' => 'The selected register does not match the cash drawer used by this order.',
                    ]);
                }
                $fiscalTcrCode = (string) $selectedRegister->fiscal_tcr_code;
            }

            if ($fiscalInvoiceType === 'CASH' && $fiscalTcrCode === null) {
                $configuredRegisters = DB::table('cash_registers')
                    ->where('business_id', $business->id)
                    ->where('location_id', $order->location_id)
                    ->where('is_active', true)
                    ->whereNotNull('fiscal_tcr_code')
                    ->get(['id','fiscal_tcr_code']);

                if ($configuredRegisters->count() === 1) {
                    $fiscalTcrCode = (string) $configuredRegisters->first()->fiscal_tcr_code;
                } elseif ($configuredRegisters->count() > 1) {
                    throw ValidationException::withMessages([
                        'cash_register_id' => 'Select the fiscal register/TCR that issues this CASH invoice.',
                    ]);
                }
            }

            $profileConfigured = DB::table('fiscalization_profiles')
                ->where('business_id', $business->id)
                ->whereIn('status', ['configured','active'])
                ->exists();

            if ($profileConfigured && $fiscalInvoiceType === 'CASH' && $fiscalTcrCode === null) {
                throw ValidationException::withMessages([
                    'cash_register_id' => 'A configured fiscalization profile requires a TCR before issuing a CASH invoice.',
                ]);
            }

            if ($fiscalInvoiceType === 'NONCASH' && $selectedRegister) {
                throw ValidationException::withMessages([
                    'cash_register_id' => 'NONCASH invoices do not use a TCR register.',
                ]);
            }

            $currencySnapshot = $this->currencySnapshot($business, $order, $allocation, $payload);
            $businessNow = CarbonImmutable::now($business->timezone);
            $invoiceId = (string) \Illuminate\Support\Str::ulid();

            DB::table('invoices')->insert([
                'id' => $invoiceId,
                'business_id' => $business->id,
                'location_id' => $order->location_id,
                'order_id' => $order->id,
                'order_number_snapshot' => $order->number,
                'created_by_user_id' => $user->id,
                'business_name_snapshot' => $business->name,
                'business_legal_name_snapshot' => $business->legal_name,
                'business_tax_number_snapshot' => $business->tax_number,
                'location_name_snapshot' => $location->name,
                'location_address_snapshot' => $location->address,
                'fiscal_operator_code_snapshot' => $operatorCode,
                'fiscal_business_unit_code_snapshot' => $location->fiscal_business_unit_code,
                'fiscal_tcr_code_snapshot' => $fiscalTcrCode,
                'number' => $this->nextNumber($business, $businessNow),
                'status' => 'issued',
                'fiscal_invoice_type' => $fiscalInvoiceType,
                'currency' => $order->currency,
                'invoice_currency' => $currencySnapshot['invoice_currency'],
                'exchange_rate' => $currencySnapshot['exchange_rate'],
                'exchange_rate_source' => $currencySnapshot['exchange_rate_source'],
                'exchange_rate_effective_at' => $currencySnapshot['exchange_rate_effective_at'],
                'subtotal' => $allocation['subtotal'],
                'discount_total' => $allocation['discount_total'],
                'tax_total' => $allocation['tax_total'],
                'grand_total' => $allocation['grand_total'],
                'subtotal_foreign' => $currencySnapshot['subtotal_foreign'],
                'discount_total_foreign' => $currencySnapshot['discount_total_foreign'],
                'tax_total_foreign' => $currencySnapshot['tax_total_foreign'],
                'grand_total_foreign' => $currencySnapshot['grand_total_foreign'],
                'customer_name' => $payload['customer_name'] ?? null,
                'customer_tax_number' => $payload['customer_tax_number'] ?? null,
                'issued_at' => $businessNow->utc(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($allocation['lines'] as $line) {
                DB::table('invoice_lines')->insert([
                    'id' => (string) \Illuminate\Support\Str::ulid(),
                    'business_id' => $business->id,
                    'invoice_id' => $invoiceId,
                    'position' => $line['position'],
                    'product_name_snapshot' => $line['product_name_snapshot'],
                    'sku_snapshot' => $line['sku_snapshot'],
                    'unit_code_snapshot' => $line['unit_code_snapshot'],
                    'unit_label_snapshot' => $line['unit_label_snapshot'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'unit_price_foreign' => $this->foreignAmount($line['unit_price'], $currencySnapshot['exchange_rate']),
                    'discount_percent' => $line['discount_percent'],
                    'tax_rate' => $line['tax_rate'],
                    'line_subtotal' => $line['line_subtotal'],
                    'line_tax' => $line['line_tax'],
                    'line_total' => $line['line_total'],
                    'line_subtotal_foreign' => $this->foreignAmount($line['line_subtotal'], $currencySnapshot['exchange_rate']),
                    'line_tax_foreign' => $this->foreignAmount($line['line_tax'], $currencySnapshot['exchange_rate']),
                    'line_total_foreign' => $this->foreignAmount($line['line_total'], $currencySnapshot['exchange_rate']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach ($payments->values() as $index => $payment) {
                DB::table('invoice_payment_snapshots')->insert([
                    'id' => (string) \Illuminate\Support\Str::ulid(),
                    'business_id' => $business->id,
                    'invoice_id' => $invoiceId,
                    'position' => $index + 1,
                    'method' => $payment->method,
                    'method_label' => $this->paymentMapper->map((string) $payment->method)['label'],
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'amount_base' => $payment->amount_base,
                    'base_currency' => $payment->base_currency,
                    'exchange_rate' => $payment->exchange_rate,
                    'external_reference' => $payment->external_reference,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $invoice = DB::table('invoices')->where('business_id', $business->id)->where('id', $invoiceId)->firstOrFail();

            return $this->withLines($business, $invoice);
        }, attempts: 3);
    }

    private function currencySnapshot(Business $business, object $order, array $allocation, array $payload): array
    {
        $baseCurrency = strtoupper((string) $order->currency);
        $invoiceCurrency = strtoupper((string) ($payload['invoice_currency'] ?? $baseCurrency));

        if ($invoiceCurrency === $baseCurrency) {
            return [
                'invoice_currency' => $baseCurrency,
                'exchange_rate' => null,
                'exchange_rate_source' => null,
                'exchange_rate_effective_at' => null,
                'subtotal_foreign' => null,
                'discount_total_foreign' => null,
                'tax_total_foreign' => null,
                'grand_total_foreign' => null,
            ];
        }

        if ($baseCurrency !== strtoupper((string) $business->currency)) {
            throw ValidationException::withMessages([
                'invoice_currency' => 'Foreign-currency invoices require the order currency to match the business base currency.',
            ]);
        }

        try {
            $conversion = $this->converter->convert('1', $invoiceCurrency, $baseCurrency);
        } catch (\DomainException) {
            throw ValidationException::withMessages([
                'invoice_currency' => "No current exchange rate is configured for {$invoiceCurrency}/{$baseCurrency}.",
            ]);
        }

        $rate = BigDecimal::of((string) $conversion['rate']);
        if (! $rate->isPositive()) {
            throw ValidationException::withMessages([
                'invoice_currency' => 'The configured exchange rate must be positive.',
            ]);
        }

        return [
            'invoice_currency' => $invoiceCurrency,
            'exchange_rate' => (string) $rate->toScale(10, RoundingMode::HALF_UP),
            'exchange_rate_source' => (string) $conversion['source'],
            'exchange_rate_effective_at' => $conversion['effective_at'],
            'subtotal_foreign' => $this->foreignAmount($allocation['subtotal'], (string) $rate),
            'discount_total_foreign' => $this->foreignAmount($allocation['discount_total'], (string) $rate),
            'tax_total_foreign' => $this->foreignAmount($allocation['tax_total'], (string) $rate),
            'grand_total_foreign' => $this->foreignAmount($allocation['grand_total'], (string) $rate),
        ];
    }

    private function foreignAmount(string|int|float $amount, ?string $exchangeRate): ?string
    {
        if ($exchangeRate === null) {
            return null;
        }

        return (string) BigDecimal::of((string) $amount)
            ->dividedBy($exchangeRate, self::MONEY_SCALE, RoundingMode::HALF_UP);
    }

    private function nextNumber(Business $business, CarbonImmutable $businessNow): string
    {
        $businessDate = $businessNow->toDateString();

        DB::statement(
            'INSERT INTO business_invoice_counters (business_id, business_date, last_number, created_at, updated_at)
             VALUES (?, ?, LAST_INSERT_ID(1), UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1), updated_at = UTC_TIMESTAMP()',
            [$business->id, $businessDate],
        );

        $sequence = (int) DB::selectOne('SELECT LAST_INSERT_ID() AS sequence')->sequence;

        return sprintf('INV-%s-%04d', $businessNow->format('Ymd'), $sequence);
    }

    private function netPaidBase(Business $business, string $orderId): BigDecimal
    {
        $paid = BigDecimal::of((string) DB::table('payments')
            ->where('business_id', $business->id)
            ->where('order_id', $orderId)
            ->where('status', 'completed')
            ->sum('amount_base'));

        $refunded = BigDecimal::of((string) DB::table('payment_refunds as pr')
            ->join('payments as p', 'p.id', '=', 'pr.payment_id')
            ->where('pr.business_id', $business->id)
            ->where('p.business_id', $business->id)
            ->where('p.order_id', $orderId)
            ->where('pr.status', 'completed')
            ->sum('pr.amount_base'));

        return $paid->minus($refunded);
    }

    private function assertReplay(object $existing, array $payload): void
    {
        $same = (string) ($existing->customer_name ?? '') === (string) ($payload['customer_name'] ?? '')
            && (string) ($existing->customer_tax_number ?? '') === (string) ($payload['customer_tax_number'] ?? '')
            && strtoupper((string) ($existing->invoice_currency ?? $existing->currency)) === strtoupper((string) ($payload['invoice_currency'] ?? $existing->currency));

        if (! empty($payload['cash_register_id'])) {
            $selectedTcr = DB::table('cash_registers')->where('id', $payload['cash_register_id'])->value('fiscal_tcr_code');
            $same = $same && (string) ($existing->fiscal_tcr_code_snapshot ?? '') === (string) ($selectedTcr ?? '');
        }

        if (! $same) {
            throw ValidationException::withMessages([
                'order_id' => 'This order already has an issued invoice with different customer details.',
            ]);
        }
    }

    private function withLines(Business $business, object $invoice): object
    {
        $invoice->lines = DB::table('invoice_lines')
            ->where('business_id', $business->id)
            ->where('invoice_id', $invoice->id)
            ->orderBy('position')
            ->get();

        $invoice->payments = DB::table('invoice_payment_snapshots')
            ->where('business_id', $business->id)
            ->where('invoice_id', $invoice->id)
            ->orderBy('position')
            ->get();

        return $invoice;
    }
}
