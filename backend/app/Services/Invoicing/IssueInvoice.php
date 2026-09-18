<?php

namespace App\Services\Invoicing;

use App\Models\Business;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class IssueInvoice
{
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

            $items = DB::table('order_items')
                ->where('business_id', $business->id)
                ->where('order_id', $order->id)
                ->whereNull('voided_at')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    'order_id' => 'An invoice requires at least one active order item.',
                ]);
            }

            $location = DB::table('locations')
                ->where('business_id', $business->id)
                ->where('id', $order->location_id)
                ->first();

            abort_unless($location, 422, 'The order location is no longer available.');

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
                'number' => $this->nextNumber($business, $businessNow),
                'status' => 'issued',
                'currency' => $order->currency,
                'subtotal' => $order->subtotal,
                'discount_total' => $order->discount_total,
                'tax_total' => $order->tax_total,
                'grand_total' => $order->grand_total,
                'customer_name' => $payload['customer_name'] ?? null,
                'customer_tax_number' => $payload['customer_tax_number'] ?? null,
                'issued_at' => $businessNow->utc(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($items->values() as $index => $item) {
                DB::table('invoice_lines')->insert([
                    'id' => (string) \Illuminate\Support\Str::ulid(),
                    'business_id' => $business->id,
                    'invoice_id' => $invoiceId,
                    'position' => $index + 1,
                    'product_name_snapshot' => $item->product_name_snapshot,
                    'sku_snapshot' => $item->sku_snapshot,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'tax_rate' => $item->tax_rate,
                    'line_subtotal' => $item->line_subtotal,
                    'line_tax' => $item->line_tax,
                    'line_total' => $item->line_total,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $invoice = DB::table('invoices')->where('business_id', $business->id)->where('id', $invoiceId)->firstOrFail();

            return $this->withLines($business, $invoice);
        }, attempts: 3);
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
            && (string) ($existing->customer_tax_number ?? '') === (string) ($payload['customer_tax_number'] ?? '');

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

        return $invoice;
    }
}
