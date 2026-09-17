<?php

namespace App\Services\Sales;

use App\Models\Business;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OrderLifecycleService
{
    private const PREPARATION_TRANSITIONS = [
        'sent' => ['preparing'],
        'preparing' => ['ready'],
        'ready' => ['served'],
    ];

    public function transitionPreparation(Business $business, OrderItem $item, string $target): OrderItem
    {
        return DB::transaction(function () use ($business, $item, $target): OrderItem {
            $item = OrderItem::query()
                ->where('business_id', $business->getKey())
                ->whereKey($item->getKey())
                ->with('order')
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($item->order->status, ['cancelled', 'closed'], true)) {
                throw ValidationException::withMessages(['order' => 'Items on a cancelled or closed order cannot be changed.']);
            }

            $allowed = self::PREPARATION_TRANSITIONS[$item->preparation_status] ?? [];
            if (! in_array($target, $allowed, true)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot move an item from {$item->preparation_status} to {$target}.",
                ]);
            }

            $timestamp = match ($target) {
                'preparing' => 'preparing_at',
                'ready' => 'ready_at',
                'served' => 'served_at',
            };

            $item->forceFill([
                'preparation_status' => $target,
                $timestamp => now(),
            ])->save();

            return $item->fresh();
        }, attempts: 3);
    }

    public function voidItem(Business $business, User $user, OrderItem $item, string $reason): OrderItem
    {
        return DB::transaction(function () use ($business, $user, $item, $reason): OrderItem {
            $item = OrderItem::query()
                ->where('business_id', $business->getKey())
                ->whereKey($item->getKey())
                ->with('order')
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($item->order->status, ['paid', 'closed', 'cancelled'], true)) {
                throw ValidationException::withMessages(['item' => 'Items on a paid, closed, or cancelled order cannot be voided.']);
            }
            if ($item->preparation_status === 'voided') {
                throw ValidationException::withMessages(['item' => 'This item is already voided.']);
            }

            $item->forceFill([
                'preparation_status' => 'voided',
                'void_reason' => trim($reason),
                'voided_by_user_id' => $user->getKey(),
                'voided_at' => now(),
            ])->save();

            return $item->fresh();
        }, attempts: 3);
    }

    public function cancelOrder(Business $business, User $user, Order $order, string $reason): Order
    {
        return DB::transaction(function () use ($business, $user, $order, $reason): Order {
            $order = Order::query()->forBusiness($business)
                ->whereKey($order->getKey())
                ->with('items')
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($order->status, ['paid', 'closed', 'cancelled'], true)) {
                throw ValidationException::withMessages(['order' => 'A paid, closed, or already cancelled order cannot be cancelled.']);
            }

            $hasCompletedPayment = $order->payments()->where('status', 'completed')->exists();
            if ($hasCompletedPayment) {
                throw ValidationException::withMessages([
                    'order' => 'An order with completed payments must be refunded before it can be cancelled.',
                ]);
            }

            $now = now();
            $order->items()
                ->where('preparation_status', '!=', 'voided')
                ->update([
                    'preparation_status' => 'voided',
                    'void_reason' => 'Order cancelled: '.trim($reason),
                    'voided_by_user_id' => $user->getKey(),
                    'voided_at' => $now,
                    'updated_at' => $now,
                ]);

            $order->forceFill([
                'status' => 'cancelled',
                'cancel_reason' => trim($reason),
                'cancelled_by_user_id' => $user->getKey(),
                'cancelled_at' => $now,
            ])->save();

            return $order->fresh('items');
        }, attempts: 3);
    }
}
