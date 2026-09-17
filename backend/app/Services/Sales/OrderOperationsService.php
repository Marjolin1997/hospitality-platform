<?php

namespace App\Services\Sales;

use App\Models\Business;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\VenueTable;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OrderOperationsService
{
    private const EDITABLE_ORDER_STATES = ['open', 'payment_due'];

    public function __construct(private readonly OrderTotalsCalculator $totals) {}

    public function addItem(Business $business, Order $order, array $payload): Order
    {
        return DB::transaction(function () use ($business, $order, $payload): Order {
            $order = $this->lockEditableOrder($business, $order);
            $this->assertNoCompletedPayments($order, 'Items cannot be added after payment has started.');

            $product = Product::query()->forBusiness($business)->whereKey($payload['product_id'])->where('is_active', true)->lockForUpdate()->firstOrFail();
            $quantity = $this->positiveDecimal($payload['quantity'], 'quantity');
            $line = $this->lineAmounts($quantity, (string) $product->sale_price, (string) $product->tax_rate);

            $order->items()->create([
                'business_id' => $business->getKey(),
                'product_id' => $product->getKey(),
                'product_name_snapshot' => $product->name,
                'sku_snapshot' => $product->sku,
                'quantity' => $quantity,
                'unit_price' => (string) BigDecimal::of((string) $product->sale_price)->toScale(4, RoundingMode::HALF_UP),
                'tax_rate' => (string) BigDecimal::of((string) $product->tax_rate)->toScale(4, RoundingMode::HALF_UP),
                ...$line,
                'preparation_station' => $product->preparation_station,
                'preparation_status' => 'pending',
                'note' => $payload['note'] ?? null,
            ]);

            return $this->recalculate($order);
        }, attempts: 3);
    }

    public function updateItem(Business $business, OrderItem $item, array $payload): Order
    {
        return DB::transaction(function () use ($business, $item, $payload): Order {
            $item = $this->lockEditableItem($business, $item);
            $order = $this->lockEditableOrder($business, $item->order);
            $this->assertNoCompletedPayments($order, 'Items cannot be edited after payment has started.');

            if ($item->preparation_status !== 'pending') {
                throw ValidationException::withMessages(['item' => 'Only unsent items can be edited.']);
            }

            $quantity = array_key_exists('quantity', $payload) ? $this->positiveDecimal($payload['quantity'], 'quantity') : (string) $item->quantity;
            $item->forceFill([
                'quantity' => $quantity,
                'note' => array_key_exists('note', $payload) ? $payload['note'] : $item->note,
                ...$this->lineAmounts($quantity, (string) $item->unit_price, (string) $item->tax_rate),
            ])->save();

            return $this->recalculate($order);
        }, attempts: 3);
    }

    public function removeItem(Business $business, User $user, OrderItem $item, string $reason): Order
    {
        return DB::transaction(function () use ($business, $user, $item, $reason): Order {
            $item = $this->lockEditableItem($business, $item);
            $order = $this->lockEditableOrder($business, $item->order);
            $this->assertNoCompletedPayments($order, 'Items cannot be removed after payment has started.');

            if ($item->preparation_status !== 'pending') {
                throw ValidationException::withMessages(['item' => 'Sent items must use the operational void flow instead of removal.']);
            }
            if ($order->items()->where('preparation_status', '!=', 'voided')->count() <= 1) {
                throw ValidationException::withMessages(['item' => 'The last active item cannot be removed; cancel the order instead.']);
            }

            $item->forceFill([
                'preparation_status' => 'voided',
                'void_reason' => trim($reason),
                'voided_by_user_id' => $user->getKey(),
                'voided_at' => now(),
            ])->save();

            return $this->recalculate($order);
        }, attempts: 3);
    }

    public function overridePrice(Business $business, User $user, OrderItem $item, string $unitPrice, string $reason): Order
    {
        return DB::transaction(function () use ($business, $user, $item, $unitPrice, $reason): Order {
            $item = $this->lockEditableItem($business, $item);
            $order = $this->lockEditableOrder($business, $item->order);
            $this->assertNoCompletedPayments($order, 'Prices cannot be overridden after payment has started.');

            if ($item->preparation_status !== 'pending') {
                throw ValidationException::withMessages(['item' => 'Only unsent items can have their price overridden.']);
            }

            $price = $this->nonNegativeDecimal($unitPrice, 'unit_price');
            $original = $item->original_unit_price ?? $item->unit_price;
            $item->forceFill([
                'original_unit_price' => $original,
                'unit_price' => $price,
                'price_override_reason' => trim($reason),
                'price_overridden_by_user_id' => $user->getKey(),
                'price_overridden_at' => now(),
                ...$this->lineAmounts((string) $item->quantity, $price, (string) $item->tax_rate),
            ])->save();

            return $this->recalculate($order);
        }, attempts: 3);
    }

    public function applyDiscount(Business $business, User $user, Order $order, string $amount, string $reason): Order
    {
        return DB::transaction(function () use ($business, $user, $order, $amount, $reason): Order {
            $order = $this->lockEditableOrder($business, $order);
            $this->assertNoCompletedPayments($order, 'Discounts cannot be changed after payment has started.');
            $discount = BigDecimal::of($this->nonNegativeDecimal($amount, 'amount'));

            $base = $this->activeItemsTotal($order);
            if ($discount->isGreaterThan($base)) {
                throw ValidationException::withMessages(['amount' => 'Discount cannot exceed the active order total.']);
            }

            $order->forceFill([
                'discount_total' => (string) $discount->toScale(4, RoundingMode::HALF_UP),
                'discount_reason' => trim($reason),
                'discount_applied_by_user_id' => $user->getKey(),
                'discount_applied_at' => now(),
            ])->save();

            return $this->recalculate($order);
        }, attempts: 3);
    }

    public function moveTable(Business $business, User $user, Order $order, string $tableId, string $reason): Order
    {
        return DB::transaction(function () use ($business, $user, $order, $tableId, $reason): Order {
            $order = $this->lockEditableOrder($business, $order);
            $this->assertNoCompletedPayments($order, 'A table cannot be moved after payment has started.');
            if ($order->type !== 'table') {
                throw ValidationException::withMessages(['order' => 'Only table orders can be moved between tables.']);
            }

            $table = VenueTable::query()->forBusiness($business)->whereKey($tableId)->where('location_id', $order->location_id)->where('is_active', true)->lockForUpdate()->firstOrFail();
            if ((string) $order->venue_table_id === (string) $table->getKey()) {
                throw ValidationException::withMessages(['venue_table_id' => 'The order is already assigned to this table.']);
            }

            $occupied = Order::query()->forBusiness($business)
                ->where('location_id', $order->location_id)
                ->where('venue_table_id', $table->getKey())
                ->whereIn('status', self::EDITABLE_ORDER_STATES)
                ->whereKeyNot($order->getKey())
                ->lockForUpdate()
                ->exists();
            if ($occupied) {
                throw ValidationException::withMessages(['venue_table_id' => 'The destination table already has an active order; use merge instead.']);
            }

            $order->forceFill([
                'previous_venue_table_id' => $order->venue_table_id,
                'venue_table_id' => $table->getKey(),
                'table_move_reason' => trim($reason),
                'table_moved_by_user_id' => $user->getKey(),
                'table_moved_at' => now(),
            ])->save();

            return $order->fresh(['items', 'payments.refunds']);
        }, attempts: 3);
    }

    private function recalculate(Order $order): Order
    {
        $order->load('items');
        $active = $order->items->where('preparation_status', '!=', 'voided');
        if ($active->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'An active order must contain at least one active item.']);
        }

        $totals = $this->totals->calculate($active->map(fn (OrderItem $item) => [
            'quantity' => (string) $item->quantity,
            'unit_price' => (string) $item->unit_price,
            'tax_rate' => (string) $item->tax_rate,
        ])->values()->all());

        $discount = BigDecimal::of((string) $order->discount_total);
        $gross = BigDecimal::of($totals['grand_total']);
        if ($discount->isGreaterThan($gross)) {
            throw ValidationException::withMessages(['discount_total' => 'Existing discount exceeds the recalculated order total.']);
        }

        $order->forceFill([
            'subtotal' => $totals['subtotal'],
            'tax_total' => $totals['tax_total'],
            'grand_total' => (string) $gross->minus($discount)->toScale(4, RoundingMode::HALF_UP),
        ])->save();

        return $order->fresh(['items', 'payments.refunds']);
    }

    private function activeItemsTotal(Order $order): BigDecimal
    {
        $order->load('items');
        $totals = $this->totals->calculate($order->items->where('preparation_status', '!=', 'voided')->map(fn (OrderItem $item) => [
            'quantity' => (string) $item->quantity,
            'unit_price' => (string) $item->unit_price,
            'tax_rate' => (string) $item->tax_rate,
        ])->values()->all());
        return BigDecimal::of($totals['grand_total']);
    }

    private function lineAmounts(string $quantity, string $price, string $taxRate): array
    {
        $totals = $this->totals->calculate([['quantity' => $quantity, 'unit_price' => $price, 'tax_rate' => $taxRate]]);
        return ['line_subtotal' => $totals['subtotal'], 'line_tax' => $totals['tax_total'], 'line_total' => $totals['grand_total']];
    }

    private function lockEditableOrder(Business $business, Order $order): Order
    {
        $record = Order::query()->forBusiness($business)->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
        if (! in_array($record->status, self::EDITABLE_ORDER_STATES, true)) {
            throw ValidationException::withMessages(['order' => 'This order is not editable in its current state.']);
        }
        return $record;
    }

    private function lockEditableItem(Business $business, OrderItem $item): OrderItem
    {
        return OrderItem::query()->forBusiness($business)->whereKey($item->getKey())->with('order')->lockForUpdate()->firstOrFail();
    }

    private function assertNoCompletedPayments(Order $order, string $message): void
    {
        if ($order->payments()->where('status', 'completed')->exists()) {
            throw ValidationException::withMessages(['order' => $message]);
        }
    }

    private function positiveDecimal(mixed $value, string $field): string
    {
        $decimal = BigDecimal::of((string) $value);
        if ($decimal->isLessThanOrEqualTo(BigDecimal::zero())) {
            throw ValidationException::withMessages([$field => 'Value must be greater than zero.']);
        }
        return (string) $decimal->toScale(4, RoundingMode::HALF_UP);
    }

    private function nonNegativeDecimal(mixed $value, string $field): string
    {
        $decimal = BigDecimal::of((string) $value);
        if ($decimal->isNegative()) {
            throw ValidationException::withMessages([$field => 'Value cannot be negative.']);
        }
        return (string) $decimal->toScale(4, RoundingMode::HALF_UP);
    }
}
