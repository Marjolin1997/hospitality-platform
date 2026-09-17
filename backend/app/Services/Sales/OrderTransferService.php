<?php

namespace App\Services\Sales;

use App\Models\Business;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\VenueTable;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class OrderTransferService
{
    private const EDITABLE = ['open', 'payment_due'];

    public function __construct(private readonly OrderTotalsCalculator $totals) {}

    public function split(Business $business, User $user, Order $source, array $payload): array
    {
        return DB::transaction(function () use ($business, $user, $source, $payload): array {
            $source = $this->lockOrder($business, $source->getKey());
            $this->assertTransferable($source);
            $this->assertNoDiscount($source);

            $items = OrderItem::query()->forBusiness($business)
                ->where('order_id', $source->getKey())
                ->whereIn('id', $payload['item_ids'])
                ->orderBy('id')->lockForUpdate()->get();
            if ($items->count() !== count($payload['item_ids'])) {
                throw ValidationException::withMessages(['item_ids' => 'One or more selected items do not belong to this order.']);
            }
            if ($items->contains(fn (OrderItem $item) => $item->preparation_status === 'voided')) {
                throw ValidationException::withMessages(['item_ids' => 'Voided items cannot be split.']);
            }

            $activeCount = $source->items()->where('preparation_status', '!=', 'voided')->count();
            if ($items->count() >= $activeCount) {
                throw ValidationException::withMessages(['item_ids' => 'A split must leave at least one active item on the source order.']);
            }

            $destinationTableId = $payload['venue_table_id'] ?? null;
            if ($destinationTableId !== null) {
                if ($source->type !== 'table') {
                    throw ValidationException::withMessages(['venue_table_id' => 'Only table orders can be split to another table.']);
                }
                $this->lockAvailableTable($business, $source, $destinationTableId);
            }

            $before = BigDecimal::of((string) $source->grand_total);
            $destination = Order::query()->create([
                'business_id' => $business->getKey(),
                'location_id' => $source->location_id,
                'venue_table_id' => $destinationTableId ?? $source->venue_table_id,
                'opened_by_user_id' => $user->getKey(),
                'number' => $this->nextNumber($business),
                'type' => $source->type,
                'status' => 'open',
                'currency' => $source->currency,
                'subtotal' => '0.0000', 'discount_total' => '0.0000', 'tax_total' => '0.0000', 'grand_total' => '0.0000',
                'opened_at' => now(),
            ]);

            OrderItem::query()->whereIn('id', $items->pluck('id'))->update(['order_id' => $destination->getKey(), 'updated_at' => now()]);
            $source = $this->recalculate($source);
            $destination = $this->recalculate($destination);
            $this->assertConserved($before, $source, $destination);
            $this->audit($business, $user, 'split', $source, $destination, $items->pluck('id')->all(), $payload['reason'], $before);

            return ['source' => $source, 'destination' => $destination];
        }, attempts: 3);
    }

    public function merge(Business $business, User $user, Order $destination, string $sourceOrderId, string $reason): Order
    {
        return DB::transaction(function () use ($business, $user, $destination, $sourceOrderId, $reason): Order {
            if ((string) $destination->getKey() === $sourceOrderId) {
                throw ValidationException::withMessages(['source_order_id' => 'An order cannot be merged into itself.']);
            }

            $ids = collect([(string) $destination->getKey(), $sourceOrderId])->sort()->values();
            $locked = Order::query()->forBusiness($business)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($locked->count() !== 2) {
                abort(404);
            }
            $destination = $locked->get((string) $destination->getKey());
            $source = $locked->get($sourceOrderId);
            $this->assertTransferable($source);
            $this->assertTransferable($destination);
            $this->assertNoDiscount($source);
            $this->assertNoDiscount($destination);

            if ((string) $source->location_id !== (string) $destination->location_id) {
                throw ValidationException::withMessages(['source_order_id' => 'Orders from different locations cannot be merged.']);
            }
            if ((string) $source->currency !== (string) $destination->currency) {
                throw ValidationException::withMessages(['source_order_id' => 'Orders with different currencies cannot be merged.']);
            }

            $sourceItems = OrderItem::query()->forBusiness($business)->where('order_id', $source->getKey())->where('preparation_status', '!=', 'voided')->orderBy('id')->lockForUpdate()->get();
            if ($sourceItems->isEmpty()) {
                throw ValidationException::withMessages(['source_order_id' => 'Source order has no active items to merge.']);
            }
            OrderItem::query()->forBusiness($business)->where('order_id', $destination->getKey())->orderBy('id')->lockForUpdate()->get();

            $before = BigDecimal::of((string) $source->grand_total)->plus((string) $destination->grand_total);
            OrderItem::query()->whereIn('id', $sourceItems->pluck('id'))->update(['order_id' => $destination->getKey(), 'updated_at' => now()]);
            $destination = $this->recalculate($destination);

            $source->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
            $source->forceFill(['subtotal'=>'0.0000','tax_total'=>'0.0000','grand_total'=>'0.0000'])->save();
            $source = $source->fresh(['items','payments.refunds']);
            $this->assertConserved($before, $source, $destination);
            $this->audit($business, $user, 'merge', $source, $destination, $sourceItems->pluck('id')->all(), $reason, $before);

            return $destination;
        }, attempts: 3);
    }

    private function lockOrder(Business $business, string $id): Order
    {
        return Order::query()->forBusiness($business)->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    private function assertTransferable(Order $order): void
    {
        if (! in_array($order->status, self::EDITABLE, true)) {
            throw ValidationException::withMessages(['order' => 'This order cannot be split or merged in its current state.']);
        }
        if ($order->payments()->where('status', 'completed')->exists()) {
            throw ValidationException::withMessages(['order' => 'Split and merge are blocked after payment has started.']);
        }
    }

    private function assertNoDiscount(Order $order): void
    {
        if (BigDecimal::of((string) $order->discount_total)->isPositive()) {
            throw ValidationException::withMessages(['order' => 'Remove the order-level discount before split or merge so financial allocation remains explicit.']);
        }
    }

    private function lockAvailableTable(Business $business, Order $source, string $tableId): void
    {
        VenueTable::query()->forBusiness($business)->whereKey($tableId)->where('location_id', $source->location_id)->where('is_active', true)->lockForUpdate()->firstOrFail();
        $occupied = Order::query()->forBusiness($business)->where('location_id', $source->location_id)->where('venue_table_id', $tableId)->whereIn('status', self::EDITABLE)->whereKeyNot($source->getKey())->lockForUpdate()->exists();
        if ($occupied) throw ValidationException::withMessages(['venue_table_id' => 'Destination table already has an active order; merge into that order instead.']);
    }

    private function recalculate(Order $order): Order
    {
        $items = OrderItem::query()->forBusiness($order->business_id)->where('order_id', $order->getKey())->where('preparation_status', '!=', 'voided')->get();
        if ($items->isEmpty()) {
            $order->forceFill(['subtotal'=>'0.0000','tax_total'=>'0.0000','grand_total'=>'0.0000'])->save();
            return $order->fresh(['items','payments.refunds']);
        }
        $calculated = $this->totals->calculate($items->map(fn(OrderItem $i)=>['quantity'=>(string)$i->quantity,'unit_price'=>(string)$i->unit_price,'tax_rate'=>(string)$i->tax_rate])->all());
        $order->forceFill(['subtotal'=>$calculated['subtotal'],'tax_total'=>$calculated['tax_total'],'grand_total'=>$calculated['grand_total']])->save();
        return $order->fresh(['items','payments.refunds']);
    }

    private function assertConserved(BigDecimal $before, Order $source, Order $destination): void
    {
        $after = BigDecimal::of((string)$source->grand_total)->plus((string)$destination->grand_total);
        if (!$before->isEqualTo($after)) {
            throw ValidationException::withMessages(['order'=>'Financial conservation check failed; no split or merge was committed.']);
        }
    }

    private function audit(Business $business, User $user, string $operation, Order $source, Order $destination, array $itemIds, string $reason, BigDecimal $before): void
    {
        DB::table('order_transfer_audits')->insert([
            'id'=>(string)Str::ulid(),'business_id'=>$business->getKey(),'location_id'=>$source->location_id,
            'source_order_id'=>$source->getKey(),'destination_order_id'=>$destination->getKey(),'performed_by_user_id'=>$user->getKey(),
            'operation'=>$operation,'reason'=>trim($reason),'total_before'=>(string)$before->toScale(4,RoundingMode::HALF_UP),
            'source_total_after'=>(string)BigDecimal::of((string)$source->grand_total)->toScale(4,RoundingMode::HALF_UP),
            'destination_total_after'=>(string)BigDecimal::of((string)$destination->grand_total)->toScale(4,RoundingMode::HALF_UP),
            'item_ids'=>json_encode(array_values($itemIds), JSON_THROW_ON_ERROR),'performed_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
        ]);
    }

    private function nextNumber(Business $business): string
    {
        $now = CarbonImmutable::now($business->timezone); $date=$now->toDateString();
        DB::statement('INSERT INTO business_order_counters (business_id,business_date,last_number,created_at,updated_at) VALUES (?,?,LAST_INSERT_ID(1),UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE last_number=LAST_INSERT_ID(last_number+1),updated_at=UTC_TIMESTAMP()',[$business->getKey(),$date]);
        $sequence=(int)DB::selectOne('SELECT LAST_INSERT_ID() AS sequence')->sequence;
        return sprintf('%s-%04d',$now->format('Ymd'),$sequence);
    }
}
