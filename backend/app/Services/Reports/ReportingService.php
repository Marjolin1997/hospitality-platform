<?php

namespace App\Services\Reports;

use App\Models\Business;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReportingService
{
    private const SCALE = 4;

    public function operational(Business $business, string $locationId, string $from, string $to): array
    {
        [$location, $fromUtc, $toUtcExclusive] = $this->context($business, $locationId, $from, $to);

        $payments = DB::table('payments as p')
            ->join('orders as o', function ($join) use ($business): void {
                $join->on('o.id', '=', 'p.order_id')
                    ->where('o.business_id', $business->getKey());
            })
            ->where('p.business_id', $business->getKey())
            ->where('o.location_id', $locationId)
            ->where('p.status', 'completed')
            ->where('p.paid_at', '>=', $fromUtc)
            ->where('p.paid_at', '<', $toUtcExclusive);

        $refunds = DB::table('payment_refunds as pr')
            ->join('payments as p', function ($join) use ($business): void {
                $join->on('p.id', '=', 'pr.payment_id')
                    ->where('p.business_id', $business->getKey());
            })
            ->join('orders as o', function ($join) use ($business): void {
                $join->on('o.id', '=', 'p.order_id')
                    ->where('o.business_id', $business->getKey());
            })
            ->where('pr.business_id', $business->getKey())
            ->where('o.location_id', $locationId)
            ->where('pr.status', 'completed')
            ->where('pr.refunded_at', '>=', $fromUtc)
            ->where('pr.refunded_at', '<', $toUtcExclusive);

        $grossSales = BigDecimal::of((string) (clone $payments)->sum('p.amount_base'));
        $refundTotal = BigDecimal::of((string) (clone $refunds)->sum('pr.amount_base'));
        $netSales = $grossSales->minus($refundTotal);
        $paidOrderCount = (int) (clone $payments)->distinct()->count('p.order_id');
        $averageTicket = $paidOrderCount > 0
            ? $netSales->dividedBy($paidOrderCount, self::SCALE, RoundingMode::HALF_UP)
            : BigDecimal::zero();

        $paymentGross = (clone $payments)
            ->select('p.method')
            ->selectRaw('SUM(p.amount_base) as gross_amount')
            ->groupBy('p.method')
            ->get()
            ->keyBy('method');

        $paymentRefunds = (clone $refunds)
            ->select('p.method')
            ->selectRaw('SUM(pr.amount_base) as refund_amount')
            ->groupBy('p.method')
            ->get()
            ->keyBy('method');

        $methods = $paymentGross->keys()
            ->merge($paymentRefunds->keys())
            ->unique()
            ->sort()
            ->values();

        $paymentMix = $methods->map(function (string $method) use ($paymentGross, $paymentRefunds): array {
            $gross = BigDecimal::of((string) ($paymentGross->get($method)?->gross_amount ?? '0'));
            $refunded = BigDecimal::of((string) ($paymentRefunds->get($method)?->refund_amount ?? '0'));

            return [
                'method' => $method,
                'gross_amount' => $this->decimal($gross),
                'refund_amount' => $this->decimal($refunded),
                'net_amount' => $this->decimal($gross->minus($refunded)),
            ];
        })->all();

        $ordersOpened = DB::table('orders')
            ->where('business_id', $business->getKey())
            ->where('location_id', $locationId)
            ->where('opened_at', '>=', $fromUtc)
            ->where('opened_at', '<', $toUtcExclusive)
            ->count();

        $cancelledOrders = DB::table('orders')
            ->where('business_id', $business->getKey())
            ->where('location_id', $locationId)
            ->whereNotNull('cancelled_at')
            ->where('cancelled_at', '>=', $fromUtc)
            ->where('cancelled_at', '<', $toUtcExclusive)
            ->count();

        $discounts = BigDecimal::of((string) DB::table('orders')
            ->where('business_id', $business->getKey())
            ->where('location_id', $locationId)
            ->whereNotNull('discount_applied_at')
            ->where('discount_applied_at', '>=', $fromUtc)
            ->where('discount_applied_at', '<', $toUtcExclusive)
            ->sum('discount_total'));

        $voidStats = DB::table('order_items as oi')
            ->join('orders as o', function ($join) use ($business): void {
                $join->on('o.id', '=', 'oi.order_id')
                    ->where('o.business_id', $business->getKey());
            })
            ->where('oi.business_id', $business->getKey())
            ->where('o.location_id', $locationId)
            ->whereNotNull('oi.voided_at')
            ->where('oi.voided_at', '>=', $fromUtc)
            ->where('oi.voided_at', '<', $toUtcExclusive)
            ->selectRaw('COUNT(*) as voided_items, COALESCE(SUM(oi.line_total), 0) as voided_value')
            ->first();

        $ordersByType = DB::table('orders')
            ->where('business_id', $business->getKey())
            ->where('location_id', $locationId)
            ->where('opened_at', '>=', $fromUtc)
            ->where('opened_at', '<', $toUtcExclusive)
            ->select('type')
            ->selectRaw('COUNT(*) as order_count')
            ->groupBy('type')
            ->orderBy('type')
            ->get()
            ->map(fn (object $row): array => [
                'type' => $row->type,
                'order_count' => (int) $row->order_count,
            ])
            ->all();

        $productMix = DB::table('order_items as oi')
            ->join('orders as o', function ($join) use ($business): void {
                $join->on('o.id', '=', 'oi.order_id')
                    ->where('o.business_id', $business->getKey());
            })
            ->where('oi.business_id', $business->getKey())
            ->where('o.location_id', $locationId)
            ->where('o.opened_at', '>=', $fromUtc)
            ->where('o.opened_at', '<', $toUtcExclusive)
            ->whereIn('o.status', ['paid', 'partially_refunded', 'refunded', 'closed'])
            ->where('oi.preparation_status', '!=', 'voided')
            ->select('oi.product_name_snapshot', 'oi.sku_snapshot')
            ->selectRaw('SUM(oi.quantity) as quantity')
            ->selectRaw('SUM(oi.line_total) as gross_line_value')
            ->groupBy('oi.product_name_snapshot', 'oi.sku_snapshot')
            ->orderByDesc('quantity')
            ->orderBy('oi.product_name_snapshot')
            ->limit(25)
            ->get()
            ->map(fn (object $row): array => [
                'product_name' => $row->product_name_snapshot,
                'sku' => $row->sku_snapshot,
                'quantity' => $this->decimal($row->quantity),
                'gross_line_value' => $this->decimal($row->gross_line_value),
            ])
            ->all();

        $staffActivity = DB::table('orders as o')
            ->join('users as u', 'u.id', '=', 'o.opened_by_user_id')
            ->where('o.business_id', $business->getKey())
            ->where('o.location_id', $locationId)
            ->where('o.opened_at', '>=', $fromUtc)
            ->where('o.opened_at', '<', $toUtcExclusive)
            ->select('u.id', 'u.name')
            ->selectRaw('COUNT(*) as orders_opened')
            ->selectRaw("SUM(CASE WHEN o.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders")
            ->selectRaw("COALESCE(SUM(CASE WHEN o.status <> 'cancelled' THEN o.grand_total ELSE 0 END), 0) as current_order_value")
            ->groupBy('u.id', 'u.name')
            ->orderByDesc('orders_opened')
            ->orderBy('u.name')
            ->get()
            ->map(fn (object $row): array => [
                'user_id' => (int) $row->id,
                'name' => $row->name,
                'orders_opened' => (int) $row->orders_opened,
                'cancelled_orders' => (int) $row->cancelled_orders,
                'current_order_value' => $this->decimal($row->current_order_value),
            ])
            ->all();

        return [
            'scope' => $this->scope($business, $location, $from, $to),
            'summary' => [
                'gross_sales' => $this->decimal($grossSales),
                'refunds' => $this->decimal($refundTotal),
                'net_sales' => $this->decimal($netSales),
                'orders_opened' => (int) $ordersOpened,
                'paid_order_count' => $paidOrderCount,
                'average_ticket' => $this->decimal($averageTicket),
                'cancelled_orders' => (int) $cancelledOrders,
                'discounts' => $this->decimal($discounts),
                'voided_items' => (int) ($voidStats->voided_items ?? 0),
                'voided_value' => $this->decimal($voidStats->voided_value ?? '0'),
            ],
            'payment_mix' => $paymentMix,
            'orders_by_type' => $ordersByType,
            'product_mix' => $productMix,
            'staff_activity' => $staffActivity,
            'definitions' => [
                'sales' => 'Sales are completed payment amounts in the selected period, less completed refunds by their transaction timestamps.',
                'orders' => 'Order activity is grouped by order opened_at in the selected business timezone.',
                'product_mix' => 'Product mix includes non-voided lines from paid/refunded/closed orders opened in the selected period and is not reduced by order-level discounts or refunds.',
            ],
        ];
    }

    public function financial(Business $business, string $locationId, string $from, string $to): array
    {
        [$location, $fromUtc, $toUtcExclusive] = $this->context($business, $locationId, $from, $to);

        $payments = DB::table('payments as p')
            ->join('orders as o', function ($join) use ($business): void {
                $join->on('o.id', '=', 'p.order_id')
                    ->where('o.business_id', $business->getKey());
            })
            ->where('p.business_id', $business->getKey())
            ->where('o.location_id', $locationId)
            ->where('p.status', 'completed')
            ->where('p.paid_at', '>=', $fromUtc)
            ->where('p.paid_at', '<', $toUtcExclusive);

        $refunds = DB::table('payment_refunds as pr')
            ->join('payments as p', function ($join) use ($business): void {
                $join->on('p.id', '=', 'pr.payment_id')
                    ->where('p.business_id', $business->getKey());
            })
            ->join('orders as o', function ($join) use ($business): void {
                $join->on('o.id', '=', 'p.order_id')
                    ->where('o.business_id', $business->getKey());
            })
            ->where('pr.business_id', $business->getKey())
            ->where('o.location_id', $locationId)
            ->where('pr.status', 'completed')
            ->where('pr.refunded_at', '>=', $fromUtc)
            ->where('pr.refunded_at', '<', $toUtcExclusive);

        $grossSales = BigDecimal::of((string) (clone $payments)->sum('p.amount_base'));
        $refundTotal = BigDecimal::of((string) (clone $refunds)->sum('pr.amount_base'));
        $netSales = $grossSales->minus($refundTotal);

        $expenseBase = DB::table('expenses')
            ->where('business_id', $business->getKey())
            ->where('location_id', $locationId)
            ->whereDate('expense_date', '>=', $from)
            ->whereDate('expense_date', '<=', $to)
            ->whereIn('status', ['posted', 'reversed', 'reversal']);

        $locationExpenseRows = (clone $expenseBase)->get(['amount', 'reversal_of_expense_id']);
        $locationExpenses = $locationExpenseRows->reduce(
            fn (BigDecimal $sum, object $row): BigDecimal => $row->reversal_of_expense_id
                ? $sum->minus((string) $row->amount)
                : $sum->plus((string) $row->amount),
            BigDecimal::zero(),
        );

        $unallocatedRows = DB::table('expenses')
            ->where('business_id', $business->getKey())
            ->whereNull('location_id')
            ->whereDate('expense_date', '>=', $from)
            ->whereDate('expense_date', '<=', $to)
            ->whereIn('status', ['posted', 'reversed', 'reversal'])
            ->get(['amount', 'reversal_of_expense_id']);

        $unallocatedExpenses = $unallocatedRows->reduce(
            fn (BigDecimal $sum, object $row): BigDecimal => $row->reversal_of_expense_id
                ? $sum->minus((string) $row->amount)
                : $sum->plus((string) $row->amount),
            BigDecimal::zero(),
        );

        $expenseCategories = (clone $expenseBase)
            ->select('category')
            ->selectRaw('SUM(CASE WHEN reversal_of_expense_id IS NULL THEN amount ELSE -amount END) as net_amount')
            ->groupBy('category')
            ->orderByDesc('net_amount')
            ->orderBy('category')
            ->get()
            ->map(fn (object $row): array => [
                'category' => $row->category,
                'net_amount' => $this->decimal($row->net_amount),
            ])
            ->all();

        $cashSessions = DB::table('cash_sessions')
            ->where('business_id', $business->getKey())
            ->where('location_id', $locationId)
            ->where('status', 'closed')
            ->whereNotNull('closed_at')
            ->where('closed_at', '>=', $fromUtc)
            ->where('closed_at', '<', $toUtcExclusive)
            ->whereNotNull('cash_difference');

        $cashOver = BigDecimal::of((string) (clone $cashSessions)
            ->where('cash_difference', '>', 0)
            ->sum('cash_difference'));
        $cashShortSigned = BigDecimal::of((string) (clone $cashSessions)
            ->where('cash_difference', '<', 0)
            ->sum('cash_difference'));
        $cashShort = $cashShortSigned->isNegative() ? $cashShortSigned->negated() : $cashShortSigned;
        $cashVariance = BigDecimal::of((string) (clone $cashSessions)->sum('cash_difference'));

        $invoiceStats = DB::table('invoices')
            ->where('business_id', $business->getKey())
            ->where('location_id', $locationId)
            ->whereNotNull('issued_at')
            ->where('issued_at', '>=', $fromUtc)
            ->where('issued_at', '<', $toUtcExclusive)
            ->selectRaw('COUNT(*) as invoice_count, COALESCE(SUM(grand_total), 0) as invoice_total')
            ->first();

        $creditNoteStats = DB::table('invoice_credit_notes as cn')
            ->join('invoices as i', function ($join) use ($business): void {
                $join->on('i.id', '=', 'cn.invoice_id')
                    ->where('i.business_id', $business->getKey());
            })
            ->where('cn.business_id', $business->getKey())
            ->where('i.location_id', $locationId)
            ->where('cn.issued_at', '>=', $fromUtc)
            ->where('cn.issued_at', '<', $toUtcExclusive)
            ->selectRaw('COUNT(*) as credit_note_count, COALESCE(SUM(cn.grand_total), 0) as credit_note_total')
            ->first();

        $purchaseReceiptStats = DB::table('goods_receipts as gr')
            ->join('goods_receipt_items as gri', function ($join) use ($business): void {
                $join->on('gri.goods_receipt_id', '=', 'gr.id')
                    ->where('gri.business_id', $business->getKey());
            })
            ->where('gr.business_id', $business->getKey())
            ->where('gr.location_id', $locationId)
            ->where('gr.received_at', '>=', $fromUtc)
            ->where('gr.received_at', '<', $toUtcExclusive)
            ->selectRaw('COUNT(DISTINCT gr.id) as receipt_count, COALESCE(SUM(gri.line_total), 0) as received_cost')
            ->first();

        $netAfterLocationExpenses = $netSales->minus($locationExpenses);

        return [
            'scope' => $this->scope($business, $location, $from, $to),
            'summary' => [
                'gross_sales' => $this->decimal($grossSales),
                'refunds' => $this->decimal($refundTotal),
                'net_sales' => $this->decimal($netSales),
                'location_expenses' => $this->decimal($locationExpenses),
                'unallocated_business_expenses' => $this->decimal($unallocatedExpenses),
                'net_after_location_expenses' => $this->decimal($netAfterLocationExpenses),
                'invoice_count' => (int) ($invoiceStats->invoice_count ?? 0),
                'invoice_total' => $this->decimal($invoiceStats->invoice_total ?? '0'),
                'credit_note_count' => (int) ($creditNoteStats->credit_note_count ?? 0),
                'credit_note_total' => $this->decimal($creditNoteStats->credit_note_total ?? '0'),
                'goods_receipt_count' => (int) ($purchaseReceiptStats->receipt_count ?? 0),
                'goods_received_cost' => $this->decimal($purchaseReceiptStats->received_cost ?? '0'),
            ],
            'expense_categories' => $expenseCategories,
            'cash_reconciliation' => [
                'closed_sessions' => (int) (clone $cashSessions)->count(),
                'cash_over' => $this->decimal($cashOver),
                'cash_short' => $this->decimal($cashShort),
                'net_variance' => $this->decimal($cashVariance),
            ],
            'definitions' => [
                'net_after_location_expenses' => 'Net completed payments less completed refunds and expenses explicitly assigned to this location in the selected period.',
                'unallocated_business_expenses' => 'Business-wide expenses without a location are shown separately and are not deducted from this location result.',
                'goods_received_cost' => 'Cost of goods physically received in the selected period. It is a procurement metric and is not automatically treated as an expense or cost of goods sold.',
            ],
        ];
    }

    private function context(Business $business, string $locationId, string $from, string $to): array
    {
        $location = DB::table('locations')
            ->where('business_id', $business->getKey())
            ->where('id', $locationId)
            ->first();

        if (! $location) {
            throw ValidationException::withMessages([
                'location_id' => 'The selected report location does not belong to this business.',
            ]);
        }

        $fromLocal = CarbonImmutable::createFromFormat('Y-m-d', $from, $business->timezone)->startOfDay();
        $toLocalExclusive = CarbonImmutable::createFromFormat('Y-m-d', $to, $business->timezone)->addDay()->startOfDay();

        return [$location, $fromLocal->utc(), $toLocalExclusive->utc()];
    }

    private function scope(Business $business, object $location, string $from, string $to): array
    {
        return [
            'business_id' => $business->getKey(),
            'location_id' => $location->id,
            'location_name' => $location->name,
            'currency' => $business->currency,
            'timezone' => $business->timezone,
            'from' => $from,
            'to' => $to,
        ];
    }

    private function decimal(mixed $value): string
    {
        return (string) BigDecimal::of((string) $value)->toScale(self::SCALE, RoundingMode::HALF_UP);
    }
}
