<?php

namespace App\Services\Operations;

use App\Models\Business;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class OperationsService
{
    private const SCALE = 4;

    public function saveProduct(Business $business, array $data): object
    {
        if (! empty($data['category_id'])) {
            abort_unless(DB::table('product_categories')->where('business_id', $business->id)->where('id', $data['category_id'])->where('is_active', true)->exists(), 422, 'Invalid category.');
        }

        $payload = [
            'business_id' => $business->id,
            'product_category_id' => $data['category_id'] ?? null,
            'name' => trim($data['name']),
            'sku' => isset($data['sku']) && trim((string)$data['sku']) !== '' ? trim((string)$data['sku']) : null,
            'sale_price' => $this->decimal($data['sale_price']),
            'tax_rate' => $this->decimal($data['tax_rate']),
            'preparation_station' => $data['preparation_station'] ?? null,
            'tracks_stock' => $data['tracks_stock'],
            'is_active' => $data['is_active'],
            'updated_at' => now(),
        ];

        if (! empty($data['id'])) {
            $query = DB::table('products')->where('business_id', $business->id)->where('id', $data['id']);
            abort_unless($query->exists(), 404);
            $query->update($payload);
            $id = $data['id'];
        } else {
            $id = (string) Str::ulid();
            $payload['id'] = $id;
            $payload['created_at'] = now();
            DB::table('products')->insert($payload);
        }

        return DB::table('products')->where('business_id', $business->id)->where('id', $id)->first();
    }

    public function setProductStatus(Business $business, string $productId, bool $isActive): object
    {
        $query = DB::table('products')->where('business_id', $business->id)->where('id', $productId);
        abort_unless($query->exists(), 404);
        $query->update(['is_active' => $isActive, 'updated_at' => now()]);
        $product = $query->first();
        $product->is_active = (bool) $product->is_active;
        $product->tracks_stock = (bool) $product->tracks_stock;
        return $product;
    }

    public function adjustInventory(Business $business, string $locationId, array $data, int $userId): void
    {
        abort_unless(
            DB::table('products')->where('business_id', $business->id)->where('id', $data['product_id'])->where('tracks_stock', true)->exists(),
            422,
            'Invalid stock product.'
        );

        DB::transaction(function () use ($business, $locationId, $data, $userId): void {
            $stock = DB::table('inventory_stocks')
                ->where(['business_id' => $business->id, 'location_id' => $locationId, 'product_id' => $data['product_id']])
                ->lockForUpdate()
                ->first();

            $current = BigDecimal::of((string) ($stock->quantity_on_hand ?? '0'))->toScale(self::SCALE, RoundingMode::HALF_UP);
            $delta = BigDecimal::of((string) $data['quantity_delta'])->toScale(self::SCALE, RoundingMode::HALF_UP);
            abort_if($delta->isZero(), 422, 'Quantity adjustment cannot be zero.');
            $next = $current->plus($delta)->toScale(self::SCALE, RoundingMode::HALF_UP);

            if ($next->isNegative()) {
                throw ValidationException::withMessages([
                    'quantity_delta' => 'This adjustment would make stock negative. Count the item and enter the verified correction instead.',
                ]);
            }

            if ($stock) {
                DB::table('inventory_stocks')->where('id', $stock->id)->update(['quantity_on_hand' => (string) $next, 'updated_at' => now()]);
            } else {
                DB::table('inventory_stocks')->insert([
                    'id' => (string) Str::ulid(), 'business_id' => $business->id, 'location_id' => $locationId,
                    'product_id' => $data['product_id'], 'quantity_on_hand' => (string) $next, 'reorder_level' => '0.0000',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            DB::table('inventory_movements')->insert([
                'id' => (string) Str::ulid(), 'business_id' => $business->id, 'location_id' => $locationId,
                'product_id' => $data['product_id'], 'created_by_user_id' => $userId, 'type' => 'adjustment',
                'quantity_delta' => (string) $delta, 'note' => $data['note'] ?? null, 'occurred_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function createExpense(Business $business, array $data, int $userId): object
    {
        return DB::transaction(function () use ($business, $data, $userId): object {
            $id = (string) Str::ulid();
            $amount = BigDecimal::of((string) $data['amount'])->toScale(self::SCALE, RoundingMode::HALF_UP);

            DB::table('expenses')->insert([
                'id' => $id,
                'business_id' => $business->id,
                'location_id' => $data['location_id'] ?? null,
                'created_by_user_id' => $userId,
                'category' => trim($data['category']),
                'description' => trim($data['description']),
                'amount' => (string) $amount,
                'currency' => $business->currency,
                'expense_date' => $data['expense_date'],
                'status' => 'posted',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return DB::table('expenses')->where('business_id', $business->id)->where('id', $id)->first();
        }, attempts: 3);
    }

    public function reverseExpense(Business $business, string $expenseId, string $reason, int $userId): object
    {
        return DB::transaction(function () use ($business, $expenseId, $reason, $userId): object {
            $expense = DB::table('expenses')->where('business_id', $business->id)->where('id', $expenseId)->lockForUpdate()->first();
            abort_unless($expense, 404);

            if ($expense->status !== 'posted' || $expense->reversal_of_expense_id !== null) {
                throw \Illuminate\Validation\ValidationException::withMessages(['expense' => 'Only an original posted expense can be reversed.']);
            }

            $existing = DB::table('expenses')->where('business_id', $business->id)->where('reversal_of_expense_id', $expenseId)->lockForUpdate()->first();
            if ($existing) {
                throw \Illuminate\Validation\ValidationException::withMessages(['expense' => 'This expense has already been reversed.']);
            }

            $reversalId = (string) Str::ulid();
            DB::table('expenses')->insert([
                'id' => $reversalId, 'business_id' => $business->id, 'location_id' => $expense->location_id,
                'created_by_user_id' => $userId, 'category' => $expense->category,
                'description' => 'Reversal: '.$expense->description,
                'amount' => $expense->amount, 'currency' => $expense->currency,
                'expense_date' => now($business->timezone)->toDateString(), 'status' => 'reversal',
                'reversal_of_expense_id' => $expenseId, 'reversed_by_user_id' => $userId,
                'reversal_reason' => trim($reason), 'reversed_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('expenses')->where('business_id', $business->id)->where('id', $expenseId)->update([
                'status' => 'reversed', 'reversed_by_user_id' => $userId,
                'reversal_reason' => trim($reason), 'reversed_at' => now(), 'updated_at' => now(),
            ]);

            return DB::table('expenses')->where('business_id', $business->id)->where('id', $reversalId)->first();
        }, attempts: 3);
    }

    public function financeOverview(Business $business): array
    {
        $from = now($business->timezone)->startOfMonth()->utc();
        $sales = BigDecimal::of((string) DB::table('payments')->where('business_id', $business->id)->where('status', 'completed')->where('paid_at', '>=', $from)->sum('amount_base'));
        $refunds = BigDecimal::of((string) DB::table('payment_refunds')->where('business_id', $business->id)->where('status', 'completed')->where('refunded_at', '>=', $from)->sum('amount_base'));
        $expenseFrom = $from->setTimezone($business->timezone)->toDateString();
        $postedExpenses = BigDecimal::of((string) DB::table('expenses')->where('business_id', $business->id)->whereIn('status', ['posted','reversed'])->whereNull('reversal_of_expense_id')->where('expense_date', '>=', $expenseFrom)->sum('amount'));
        $reversedExpenses = BigDecimal::of((string) DB::table('expenses')->where('business_id', $business->id)->where('status', 'reversal')->whereNotNull('reversal_of_expense_id')->where('expense_date', '>=', $expenseFrom)->sum('amount'));
        $expenses = $postedExpenses->minus($reversedExpenses);
        $netSales = $sales->minus($refunds);

        $closedCashSessions = DB::table('cash_sessions')
            ->where('business_id', $business->id)
            ->where('status', 'closed')
            ->where('closed_at', '>=', $from)
            ->whereNotNull('cash_difference');
        $cashOver = BigDecimal::of((string) (clone $closedCashSessions)->where('cash_difference', '>', 0)->sum('cash_difference'));
        $cashShortSigned = BigDecimal::of((string) (clone $closedCashSessions)->where('cash_difference', '<', 0)->sum('cash_difference'));
        $cashShort = $cashShortSigned->isNegative() ? $cashShortSigned->negated() : $cashShortSigned;
        $cashVariance = BigDecimal::of((string) (clone $closedCashSessions)->sum('cash_difference'));

        return [
            'sales' => $this->decimal($netSales),
            'gross_sales' => $this->decimal($sales),
            'refunds' => $this->decimal($refunds),
            'expenses' => $this->decimal($expenses),
            'net' => $this->decimal($netSales->minus($expenses)),
            'cash_reconciliation' => [
                'open_sessions' => DB::table('cash_sessions')->where('business_id', $business->id)->where('status', 'open')->count(),
                'closed_sessions' => (clone $closedCashSessions)->count(),
                'cash_over' => $this->decimal($cashOver),
                'cash_short' => $this->decimal($cashShort),
                'net_variance' => $this->decimal($cashVariance),
            ],
            'recent_expenses' => DB::table('expenses')->where('business_id', $business->id)->orderByDesc('expense_date')->orderByDesc('created_at')->limit(50)->get(),
        ];
    }

    public function issueInvoice(Business $business, array $data, int $userId): object
    {
        try {
            return DB::transaction(function () use ($business, $data, $userId): object {
                $order = DB::table('orders')->where('business_id', $business->id)->where('id', $data['order_id'])->lockForUpdate()->first();
                abort_unless($order, 404);
                abort_if($order->status === 'cancelled', 422, 'Cancelled orders cannot be invoiced.');

                $existing = DB::table('invoices')->where('business_id', $business->id)->where('order_id', $order->id)->first();
                if ($existing) return $existing;

                $id = (string) Str::ulid();
                $number = 'INV-'.now($business->timezone)->format('Ymd').'-'.strtoupper(substr($id, -8));
                DB::table('invoices')->insert([
                    'id' => $id, 'business_id' => $business->id, 'location_id' => $order->location_id, 'order_id' => $order->id,
                    'created_by_user_id' => $userId, 'number' => $number, 'status' => 'issued', 'currency' => $order->currency,
                    'subtotal' => $order->subtotal, 'tax_total' => $order->tax_total, 'grand_total' => $order->grand_total,
                    'customer_name' => $data['customer_name'] ?? null, 'customer_tax_number' => $data['customer_tax_number'] ?? null,
                    'issued_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                return DB::table('invoices')->where('business_id', $business->id)->where('id', $id)->first();
            });
        } catch (QueryException $exception) {
            $existing = DB::table('invoices')->where('business_id', $business->id)->where('order_id', $data['order_id'])->first();
            if ($existing) return $existing;
            throw $exception;
        }
    }

    public function updateSettings(Business $business, array $data): void
    {
        DB::transaction(function () use ($business, $data): void {
            foreach ($data as $key => $value) {
                $existing = DB::table('business_settings')->where('business_id', $business->id)->where('key', $key)->first();
                if ($existing) {
                    DB::table('business_settings')->where('id', $existing->id)->update(['value' => json_encode($value), 'updated_at' => now()]);
                } else {
                    DB::table('business_settings')->insert([
                        'id' => (string) Str::ulid(), 'business_id' => $business->id, 'key' => $key,
                        'value' => json_encode($value), 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    private function decimal(string|int|BigDecimal $value): string
    {
        return (string) BigDecimal::of((string) $value)->toScale(self::SCALE, RoundingMode::HALF_UP);
    }
}
