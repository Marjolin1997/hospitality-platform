<?php

namespace App\Services\Sales;

use App\Models\Business;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SendOrderToStation
{
    public function execute(Business $business, Order $order): Order
    {
        return DB::transaction(function () use ($business, $order): Order {
            $order = Order::query()->forBusiness($business)->whereKey($order->getKey())->with('items')->lockForUpdate()->firstOrFail();
            if (! in_array($order->status, ['open', 'payment_due'], true)) {
                throw ValidationException::withMessages(['order' => 'This order cannot be sent to preparation in its current state.']);
            }

            if ($order->payments()->whereNotIn('status', ['failed', 'cancelled', 'voided'])->exists()) {
                throw ValidationException::withMessages(['order' => 'New items cannot be sent after payment has started.']);
            }

            $pending = $order->items->where('preparation_status', 'pending');
            if ($pending->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'There are no new items to send.']);
            }

            foreach ($pending as $item) {
                $item->forceFill(['preparation_status' => 'sent', 'sent_at' => now()])->save();
            }

            return $order->fresh(['items', 'payments.refunds']);
        }, attempts: 3);
    }
}
