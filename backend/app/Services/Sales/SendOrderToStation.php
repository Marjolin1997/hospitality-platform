<?php

namespace App\Services\Sales;

use App\Models\Business;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SendOrderToStation
{
    public function execute(Business $business, Order $order): Order
    {
        return DB::transaction(function () use ($business, $order): Order {
            $order = Order::query()
                ->forBusiness($business)
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($order->status, ['open', 'payment_due'], true)) {
                throw ValidationException::withMessages([
                    'order' => 'This order cannot be sent to preparation in its current state.',
                ]);
            }

            if ($order->payments()->whereNotIn('status', ['failed', 'cancelled', 'voided'])->exists()) {
                throw ValidationException::withMessages([
                    'order' => 'New items cannot be sent after payment has started.',
                ]);
            }

            $pending = OrderItem::query()
                ->forBusiness($business)
                ->where('order_id', $order->getKey())
                ->where('preparation_status', 'pending')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($pending->isEmpty()) {
                return $order->fresh(['items', 'payments.refunds']);
            }

            $now = now();

            foreach ($pending as $item) {
                $station = trim((string) $item->preparation_station);

                if ($station === '') {
                    $item->forceFill([
                        'preparation_status' => 'served',
                        'sent_at' => $now,
                        'served_at' => $now,
                    ])->save();

                    continue;
                }

                $item->forceFill([
                    'preparation_status' => 'sent',
                    'sent_at' => $now,
                ])->save();
            }

            return $order->fresh(['items', 'payments.refunds']);
        }, attempts: 3);
    }
}
