<?php

namespace App\Services\Sales;

use App\Models\Business;
use App\Models\Location;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\VenueTable;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateOrder
{
    public function __construct(private readonly OrderTotalsCalculator $totals) {}

    public function execute(Business $business, User $user, array $payload): Order
    {
        return DB::transaction(function () use ($business, $user, $payload): Order {
            DB::table('businesses')
                ->where('id', $business->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $requestSnapshot = $this->requestSnapshot($payload);
            $existing = Order::query()
                ->forBusiness($business)
                ->where('idempotency_key', $payload['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->request_snapshot !== $requestSnapshot) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => 'This idempotency key was already used for a different order request.',
                    ]);
                }

                return $existing->load('items');
            }

            $location = Location::query()
                ->whereKey($payload['location_id'])
                ->where('business_id', $business->getKey())
                ->where('is_active', true)
                ->first();

            if (! $location) {
                throw ValidationException::withMessages(['location_id' => 'The selected location is not available for this business.']);
            }

            $table = null;
            if (! empty($payload['venue_table_id'])) {
                $table = VenueTable::query()
                    ->whereKey($payload['venue_table_id'])
                    ->where('business_id', $business->getKey())
                    ->where('location_id', $location->getKey())
                    ->where('is_active', true)
                    ->whereHas('area', fn ($query) => $query->where('is_active', true))
                    ->lockForUpdate()
                    ->first();

                if (! $table) {
                    throw ValidationException::withMessages(['venue_table_id' => 'The selected table is not available at this location.']);
                }

                $occupied = Order::query()
                    ->forBusiness($business)
                    ->where('location_id', $location->getKey())
                    ->where('venue_table_id', $table->getKey())
                    ->whereIn('status', ['open', 'payment_due'])
                    ->exists();

                if ($occupied) {
                    throw ValidationException::withMessages([
                        'venue_table_id' => 'This table already has an active order. Open that order instead of creating another one.',
                    ]);
                }
            }

            if ($payload['type'] === 'table' && ! $table) {
                throw ValidationException::withMessages(['venue_table_id' => 'A table is required for table orders.']);
            }
            if ($payload['type'] !== 'table' && $table) {
                throw ValidationException::withMessages(['venue_table_id' => 'A table can only be assigned to a table order.']);
            }

            $items = collect($payload['items'])->values();
            $productIds = $items->pluck('product_id');
            if ($productIds->unique()->count() !== $productIds->count()) {
                throw ValidationException::withMessages([
                    'items' => 'Each product may appear only once in a new order. Increase its quantity instead of sending duplicate lines.',
                ]);
            }

            $products = Product::query()
                ->forBusiness($business)
                ->whereIn('id', $productIds->all())
                ->where('is_active', true)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($products->count() !== $productIds->count()) {
                throw ValidationException::withMessages(['items' => 'One or more products are unavailable. Refresh the catalog and try again.']);
            }

            $calculationItems = $items->map(function (array $item) use ($products): array {
                $product = $products->get($item['product_id']);
                return [
                    'quantity' => (string) $item['quantity'],
                    'unit_price' => (string) $product->sale_price,
                    'tax_rate' => (string) $product->tax_rate,
                ];
            })->all();

            $totals = $this->totals->calculate($calculationItems);
            $businessNow = CarbonImmutable::now($business->timezone);

            $order = Order::query()->create([
                'business_id' => $business->getKey(),
                'location_id' => $location->getKey(),
                'venue_table_id' => $table?->getKey(),
                'opened_by_user_id' => $user->getKey(),
                'number' => $this->nextNumber($business, $businessNow),
                'idempotency_key' => $payload['idempotency_key'],
                'request_snapshot' => $requestSnapshot,
                'type' => $payload['type'],
                'status' => 'open',
                'currency' => $business->currency,
                ...$totals,
                // Database timestamps are UTC. The business-local clock is used only
                // to determine the operational business date and order-number prefix.
                'opened_at' => $businessNow->utc(),
            ]);

            foreach ($items as $item) {
                $product = $products->get($item['product_id']);
                $line = $this->totals->calculate([[
                    'quantity' => (string) $item['quantity'],
                    'unit_price' => (string) $product->sale_price,
                    'tax_rate' => (string) $product->tax_rate,
                ]]);

                $order->items()->create([
                    'business_id' => $business->getKey(),
                    'product_id' => $product->getKey(),
                    'product_name_snapshot' => $product->name,
                    'sku_snapshot' => $product->sku,
                    'quantity' => (string) $item['quantity'],
                    'unit_price' => $product->sale_price,
                    'tax_rate' => $product->tax_rate,
                    'line_subtotal' => $line['subtotal'],
                    'line_tax' => $line['tax_total'],
                    'line_total' => $line['grand_total'],
                    'preparation_station' => $product->preparation_station,
                    'preparation_status' => 'pending',
                    'note' => $item['note'] ?? null,
                ]);
            }

            return $order->load('items');
        }, attempts: 3);
    }

    private function requestSnapshot(array $payload): array
    {
        return [
            'location_id' => (string) $payload['location_id'],
            'venue_table_id' => isset($payload['venue_table_id']) && $payload['venue_table_id'] !== ''
                ? (string) $payload['venue_table_id']
                : null,
            'type' => (string) $payload['type'],
            'items' => collect($payload['items'])
                ->map(fn (array $item): array => [
                    'product_id' => (string) $item['product_id'],
                    'quantity' => (string) BigDecimal::of((string) $item['quantity'])
                        ->toScale(4, RoundingMode::HALF_UP),
                    'note' => array_key_exists('note', $item) && $item['note'] !== null
                        ? (string) $item['note']
                        : null,
                ])
                ->sortBy('product_id')
                ->values()
                ->all(),
        ];
    }

    private function nextNumber(Business $business, CarbonImmutable $businessNow): string
    {
        $businessDate = $businessNow->toDateString();

        // MySQL LAST_INSERT_ID(expr) is connection-local and atomic. The composite
        // primary key serializes increments for one business/day without count()+1
        // races, while allowing different businesses/days to progress independently.
        DB::statement(
            'INSERT INTO business_order_counters (business_id, business_date, last_number, created_at, updated_at)
             VALUES (?, ?, LAST_INSERT_ID(1), UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1), updated_at = UTC_TIMESTAMP()',
            [$business->getKey(), $businessDate],
        );

        $sequence = (int) DB::selectOne('SELECT LAST_INSERT_ID() AS sequence')->sequence;

        return sprintf('%s-%04d', $businessNow->format('Ymd'), $sequence);
    }
}
