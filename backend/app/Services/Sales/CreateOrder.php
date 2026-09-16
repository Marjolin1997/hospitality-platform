<?php

namespace App\Services\Sales;

use App\Models\Business;
use App\Models\Location;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\VenueTable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateOrder
{
    public function __construct(private readonly OrderTotalsCalculator $totals) {}

    public function execute(Business $business, User $user, array $payload): Order
    {
        return DB::transaction(function () use ($business, $user, $payload): Order {
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
                    ->first();

                if (! $table) {
                    throw ValidationException::withMessages(['venue_table_id' => 'The selected table is not available at this location.']);
                }
            }

            if ($payload['type'] === 'table' && ! $table) {
                throw ValidationException::withMessages(['venue_table_id' => 'A table is required for table orders.']);
            }

            $requestedItems = collect($payload['items'])->keyBy('product_id');
            $products = Product::query()
                ->forBusiness($business)
                ->whereIn('id', $requestedItems->keys())
                ->where('is_active', true)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($products->count() !== $requestedItems->count()) {
                throw ValidationException::withMessages(['items' => 'One or more products are unavailable. Refresh the catalog and try again.']);
            }

            $calculationItems = $requestedItems->map(function (array $item, string $productId) use ($products): array {
                $product = $products->get($productId);
                return ['quantity' => (string) $item['quantity'], 'unit_price' => (string) $product->sale_price, 'tax_rate' => (string) $product->tax_rate];
            })->values()->all();

            $totals = $this->totals->calculate($calculationItems);

            $order = Order::query()->create([
                'business_id' => $business->getKey(),
                'location_id' => $location->getKey(),
                'venue_table_id' => $table?->getKey(),
                'opened_by_user_id' => $user->getKey(),
                'number' => $this->nextNumber($business),
                'type' => $payload['type'],
                'status' => 'open',
                'currency' => $business->currency,
                ...$totals,
                'opened_at' => now(),
            ]);

            foreach ($requestedItems as $productId => $item) {
                $product = $products->get($productId);
                $line = $this->totals->calculate([['quantity' => (string) $item['quantity'], 'unit_price' => (string) $product->sale_price, 'tax_rate' => (string) $product->tax_rate]]);

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

    private function nextNumber(Business $business): string
    {
        $prefix = now()->format('Ymd');
        $count = Order::query()->forBusiness($business)->whereDate('opened_at', today())->lockForUpdate()->count() + 1;

        return sprintf('%s-%04d', $prefix, $count);
    }
}
