<?php

namespace App\Services\Inventory;

use App\Models\Business;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ManageInventoryControl
{
    private const SCALE = 4;

    public function setReorderLevel(
        Business $business,
        string $locationId,
        string $productId,
        mixed $reorderLevel,
        int $actorUserId,
    ): object {
        return DB::transaction(function () use ($business, $locationId, $productId, $reorderLevel, $actorUserId): object {
            $this->lockBusiness($business);

            $location = DB::table('locations')
                ->where('business_id', $business->getKey())
                ->where('id', $locationId)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (! $location) {
                throw ValidationException::withMessages([
                    'location_id' => 'Select an active location from this business.',
                ]);
            }

            $product = DB::table('products')
                ->where('business_id', $business->getKey())
                ->where('id', $productId)
                ->where('tracks_stock', true)
                ->lockForUpdate()
                ->first();

            if (! $product) {
                throw ValidationException::withMessages([
                    'product' => 'Select a stock-tracked product from this business.',
                ]);
            }

            $normalized = $this->nonNegativeDecimal($reorderLevel, 'reorder_level');
            $stock = $this->lockedStock($business, $locationId, $productId);
            $previous = (string) BigDecimal::of((string) ($stock->reorder_level ?? '0'))
                ->toScale(self::SCALE, RoundingMode::HALF_UP);

            if ($previous === $normalized) {
                return (object) [
                    'product_id' => $productId,
                    'location_id' => $locationId,
                    'quantity_on_hand' => (string) BigDecimal::of((string) ($stock->quantity_on_hand ?? '0'))
                        ->toScale(self::SCALE, RoundingMode::HALF_UP),
                    'reorder_level' => $normalized,
                ];
            }

            if ($stock) {
                DB::table('inventory_stocks')
                    ->where('business_id', $business->getKey())
                    ->where('id', $stock->id)
                    ->update([
                        'reorder_level' => $normalized,
                        'updated_at' => now(),
                    ]);

                $stockId = (string) $stock->id;
            } else {
                $stockId = (string) Str::ulid();

                DB::table('inventory_stocks')->insert([
                    'id' => $stockId,
                    'business_id' => $business->getKey(),
                    'location_id' => $locationId,
                    'product_id' => $productId,
                    'quantity_on_hand' => '0.0000',
                    'reorder_level' => $normalized,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('business_configuration_audits')->insert([
                'id' => (string) Str::ulid(),
                'business_id' => $business->getKey(),
                'location_id' => $locationId,
                'performed_by_user_id' => $actorUserId,
                'entity_type' => 'inventory_stock',
                'entity_id' => $stockId,
                'action' => 'reorder_level_changed',
                'previous_state' => json_encode([
                    'product_id' => $productId,
                    'product_name' => $product->name,
                    'reorder_level' => $previous,
                ], JSON_THROW_ON_ERROR),
                'new_state' => json_encode([
                    'product_id' => $productId,
                    'product_name' => $product->name,
                    'reorder_level' => $normalized,
                ], JSON_THROW_ON_ERROR),
                'performed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $updated = DB::table('inventory_stocks')
                ->where('business_id', $business->getKey())
                ->where('id', $stockId)
                ->firstOrFail();

            return (object) [
                'product_id' => $productId,
                'location_id' => $locationId,
                'quantity_on_hand' => (string) $updated->quantity_on_hand,
                'reorder_level' => (string) $updated->reorder_level,
            ];
        }, 3);
    }

    public function transfer(Business $business, array $data, int $actorUserId): object
    {
        return DB::transaction(function () use ($business, $data, $actorUserId): object {
            $this->lockBusiness($business);

            $requestSnapshot = $this->transferSnapshot($data);
            $existingTransfer = DB::table('inventory_transfers')
                ->where('business_id', $business->getKey())
                ->where('idempotency_key', $data['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($existingTransfer) {
                $storedSnapshot = json_decode($existingTransfer->request_snapshot, true, 512, JSON_THROW_ON_ERROR);

                if ($storedSnapshot !== $requestSnapshot) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => 'This idempotency key was already used for a different stock transfer request.',
                    ]);
                }

                return $existingTransfer;
            }

            if ((string) $data['source_location_id'] === (string) $data['destination_location_id']) {
                throw ValidationException::withMessages([
                    'destination_location_id' => 'Source and destination locations must be different.',
                ]);
            }

            $locations = DB::table('locations')
                ->where('business_id', $business->getKey())
                ->whereIn('id', [$data['source_location_id'], $data['destination_location_id']])
                ->where('is_active', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($locations->count() !== 2) {
                throw ValidationException::withMessages([
                    'destination_location_id' => 'Both transfer locations must be active locations in this business.',
                ]);
            }

            $items = collect($data['items'])->values();
            $productIds = $items->pluck('product_id')->values();

            $products = DB::table('products')
                ->where('business_id', $business->getKey())
                ->whereIn('id', $productIds)
                ->where('tracks_stock', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($products->count() !== $productIds->count()) {
                throw ValidationException::withMessages([
                    'items' => 'Every transfer line must reference a stock-tracked product in this business.',
                ]);
            }

            $prepared = [];

            foreach ($items->sortBy('product_id')->values() as $item) {
                $quantity = BigDecimal::of(
                    $this->positiveDecimal($item['quantity'], 'quantity')
                )->toScale(self::SCALE, RoundingMode::HALF_UP);

                $sourceStock = $this->lockedStock(
                    $business,
                    (string) $data['source_location_id'],
                    (string) $item['product_id'],
                );

                $sourceCurrent = BigDecimal::of((string) ($sourceStock->quantity_on_hand ?? '0'))
                    ->toScale(self::SCALE, RoundingMode::HALF_UP);

                if ($quantity->isGreaterThan($sourceCurrent)) {
                    $product = $products->get($item['product_id']);
                    throw ValidationException::withMessages([
                        'items' => "Transfer quantity exceeds on-hand stock for {$product->name}.",
                    ]);
                }

                $destinationStock = $this->lockedStock(
                    $business,
                    (string) $data['destination_location_id'],
                    (string) $item['product_id'],
                );

                $destinationCurrent = BigDecimal::of((string) ($destinationStock->quantity_on_hand ?? '0'))
                    ->toScale(self::SCALE, RoundingMode::HALF_UP);

                $prepared[] = [
                    'product' => $products->get($item['product_id']),
                    'quantity' => $quantity,
                    'source_stock' => $sourceStock,
                    'source_next' => $sourceCurrent->minus($quantity)->toScale(self::SCALE, RoundingMode::HALF_UP),
                    'destination_stock' => $destinationStock,
                    'destination_next' => $destinationCurrent->plus($quantity)->toScale(self::SCALE, RoundingMode::HALF_UP),
                ];
            }

            $businessNow = CarbonImmutable::now($business->timezone);
            $transferId = (string) Str::ulid();
            $number = $this->nextNumber($business, $businessNow, 'transfer', 'TRF');

            DB::table('inventory_transfers')->insert([
                'id' => $transferId,
                'business_id' => $business->getKey(),
                'source_location_id' => $data['source_location_id'],
                'destination_location_id' => $data['destination_location_id'],
                'created_by_user_id' => $actorUserId,
                'number' => $number,
                'idempotency_key' => $data['idempotency_key'],
                'request_snapshot' => json_encode($requestSnapshot, JSON_THROW_ON_ERROR),
                'status' => 'posted',
                'note' => trim((string) $data['note']),
                'posted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($prepared as $entry) {
                $product = $entry['product'];
                $quantity = $entry['quantity'];

                $this->writeStock(
                    $business,
                    (string) $data['source_location_id'],
                    (string) $product->id,
                    $entry['source_stock'],
                    $entry['source_next'],
                );

                $this->writeStock(
                    $business,
                    (string) $data['destination_location_id'],
                    (string) $product->id,
                    $entry['destination_stock'],
                    $entry['destination_next'],
                );

                DB::table('inventory_transfer_items')->insert([
                    'id' => (string) Str::ulid(),
                    'business_id' => $business->getKey(),
                    'inventory_transfer_id' => $transferId,
                    'product_id' => $product->id,
                    'product_name_snapshot' => $product->name,
                    'sku_snapshot' => $product->sku,
                    'quantity' => (string) $quantity,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->movement(
                    $business,
                    (string) $data['source_location_id'],
                    (string) $product->id,
                    $actorUserId,
                    'transfer_out',
                    (string) $quantity->negated(),
                    'inventory_transfer',
                    $transferId,
                    trim((string) $data['note']),
                );

                $this->movement(
                    $business,
                    (string) $data['destination_location_id'],
                    (string) $product->id,
                    $actorUserId,
                    'transfer_in',
                    (string) $quantity,
                    'inventory_transfer',
                    $transferId,
                    trim((string) $data['note']),
                );
            }

            return DB::table('inventory_transfers')
                ->where('business_id', $business->getKey())
                ->where('id', $transferId)
                ->firstOrFail();
        }, 3);
    }

    public function createCount(Business $business, array $data, int $actorUserId): object
    {
        return DB::transaction(function () use ($business, $data, $actorUserId): object {
            $this->lockBusiness($business);

            $location = DB::table('locations')
                ->where('business_id', $business->getKey())
                ->where('id', $data['location_id'])
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (! $location) {
                throw ValidationException::withMessages([
                    'location_id' => 'Select an active location from this business.',
                ]);
            }

            $existingDraft = DB::table('inventory_counts')
                ->where('business_id', $business->getKey())
                ->where('location_id', $location->id)
                ->where('status', 'draft')
                ->lockForUpdate()
                ->first();

            if ($existingDraft) {
                throw ValidationException::withMessages([
                    'count' => "A draft stock count ({$existingDraft->number}) is already open for this location.",
                ]);
            }

            $productQuery = DB::table('products')
                ->where('business_id', $business->getKey())
                ->where('tracks_stock', true);

            if (! empty($data['product_ids'])) {
                $productQuery->whereIn('id', $data['product_ids']);
            }

            $products = $productQuery->orderBy('id')->lockForUpdate()->get();

            if ($products->isEmpty()) {
                throw ValidationException::withMessages([
                    'product_ids' => 'No stock-tracked products are available for this count.',
                ]);
            }

            if (! empty($data['product_ids']) && $products->count() !== count($data['product_ids'])) {
                throw ValidationException::withMessages([
                    'product_ids' => 'One or more selected products are not stock-tracked products in this business.',
                ]);
            }

            $businessNow = CarbonImmutable::now($business->timezone);
            $countId = (string) Str::ulid();
            $number = $this->nextNumber($business, $businessNow, 'count', 'CNT');

            DB::table('inventory_counts')->insert([
                'id' => $countId,
                'business_id' => $business->getKey(),
                'location_id' => $location->id,
                'created_by_user_id' => $actorUserId,
                'number' => $number,
                'status' => 'draft',
                'note' => isset($data['note']) && trim((string) $data['note']) !== ''
                    ? trim((string) $data['note'])
                    : null,
                'started_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($products as $product) {
                $stock = $this->lockedStock(
                    $business,
                    (string) $location->id,
                    (string) $product->id,
                );

                $expected = BigDecimal::of((string) ($stock->quantity_on_hand ?? '0'))
                    ->toScale(self::SCALE, RoundingMode::HALF_UP);

                DB::table('inventory_count_items')->insert([
                    'id' => (string) Str::ulid(),
                    'business_id' => $business->getKey(),
                    'inventory_count_id' => $countId,
                    'product_id' => $product->id,
                    'product_name_snapshot' => $product->name,
                    'sku_snapshot' => $product->sku,
                    'expected_quantity' => (string) $expected,
                    'counted_quantity' => null,
                    'variance_quantity' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->countEvent(
                $business,
                $countId,
                $actorUserId,
                'created',
                null,
                'draft',
                ['number' => $number, 'line_count' => $products->count()],
            );

            return DB::table('inventory_counts')
                ->where('business_id', $business->getKey())
                ->where('id', $countId)
                ->firstOrFail();
        }, 3);
    }

    public function updateCount(Business $business, string $countId, array $data, int $actorUserId): object
    {
        return DB::transaction(function () use ($business, $countId, $data, $actorUserId): object {
            $this->lockBusiness($business);
            $count = $this->lockedCount($business, $countId);

            if ($count->status !== 'draft') {
                throw ValidationException::withMessages([
                    'count' => 'Only a draft stock count can be edited.',
                ]);
            }

            $requested = collect($data['items'])->keyBy('inventory_count_item_id');

            $lines = DB::table('inventory_count_items')
                ->where('business_id', $business->getKey())
                ->where('inventory_count_id', $countId)
                ->whereIn('id', $requested->keys())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($lines->count() !== $requested->count()) {
                throw ValidationException::withMessages([
                    'items' => 'One or more count lines do not belong to this stock count.',
                ]);
            }

            foreach ($lines as $line) {
                $value = $requested->get($line->id)['counted_quantity'] ?? null;
                $normalized = $value === null || $value === ''
                    ? null
                    : $this->nonNegativeDecimal($value, 'counted_quantity');

                DB::table('inventory_count_items')
                    ->where('business_id', $business->getKey())
                    ->where('id', $line->id)
                    ->update([
                        'counted_quantity' => $normalized,
                        'updated_at' => now(),
                    ]);
            }

            $this->countEvent(
                $business,
                $countId,
                $actorUserId,
                'draft_updated',
                'draft',
                'draft',
                ['updated_line_count' => $lines->count()],
            );

            return DB::table('inventory_counts')
                ->where('business_id', $business->getKey())
                ->where('id', $countId)
                ->firstOrFail();
        }, 3);
    }

    public function postCount(Business $business, string $countId, int $actorUserId): object
    {
        return DB::transaction(function () use ($business, $countId, $actorUserId): object {
            $this->lockBusiness($business);
            $count = $this->lockedCount($business, $countId);

            if ($count->status === 'posted') {
                return DB::table('inventory_counts')
                    ->where('business_id', $business->getKey())
                    ->where('id', $countId)
                    ->firstOrFail();
            }

            if ($count->status !== 'draft') {
                throw ValidationException::withMessages([
                    'count' => 'Only a draft stock count can be posted.',
                ]);
            }

            $locationActive = DB::table('locations')
                ->where('business_id', $business->getKey())
                ->where('id', $count->location_id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->exists();

            if (! $locationActive) {
                throw ValidationException::withMessages([
                    'count' => 'Reactivate the count location before posting this stock count.',
                ]);
            }

            $lines = DB::table('inventory_count_items')
                ->where('business_id', $business->getKey())
                ->where('inventory_count_id', $countId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $missing = $lines->filter(fn (object $line): bool => $line->counted_quantity === null);

            if ($missing->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'items' => 'Count every item before posting the stock count.',
                ]);
            }

            $prepared = [];
            $changedSinceStart = [];

            foreach ($lines as $line) {
                $stock = $this->lockedStock(
                    $business,
                    (string) $count->location_id,
                    (string) $line->product_id,
                );

                $current = BigDecimal::of((string) ($stock->quantity_on_hand ?? '0'))
                    ->toScale(self::SCALE, RoundingMode::HALF_UP);
                $expected = BigDecimal::of((string) $line->expected_quantity)
                    ->toScale(self::SCALE, RoundingMode::HALF_UP);
                $counted = BigDecimal::of((string) $line->counted_quantity)
                    ->toScale(self::SCALE, RoundingMode::HALF_UP);

                if (! $current->isEqualTo($expected)) {
                    $changedSinceStart[] = $line->product_name_snapshot;
                }

                $prepared[] = [
                    'line' => $line,
                    'stock' => $stock,
                    'current' => $current,
                    'counted' => $counted,
                    'variance' => $counted->minus($current)->toScale(self::SCALE, RoundingMode::HALF_UP),
                ];
            }

            if ($changedSinceStart !== []) {
                $preview = implode(', ', array_slice($changedSinceStart, 0, 5));
                $suffix = count($changedSinceStart) > 5 ? ' and more' : '';

                throw ValidationException::withMessages([
                    'count' => "Stock changed after this count started for {$preview}{$suffix}. Cancel and restart the count from a fresh snapshot.",
                ]);
            }

            $varianceLines = 0;

            foreach ($prepared as $entry) {
                $line = $entry['line'];
                $variance = $entry['variance'];

                $this->writeStock(
                    $business,
                    (string) $count->location_id,
                    (string) $line->product_id,
                    $entry['stock'],
                    $entry['counted'],
                );

                DB::table('inventory_count_items')
                    ->where('business_id', $business->getKey())
                    ->where('id', $line->id)
                    ->update([
                        'variance_quantity' => (string) $variance,
                        'updated_at' => now(),
                    ]);

                if (! $variance->isZero()) {
                    $varianceLines++;

                    $this->movement(
                        $business,
                        (string) $count->location_id,
                        (string) $line->product_id,
                        $actorUserId,
                        'stock_count',
                        (string) $variance,
                        'inventory_count',
                        $countId,
                        'Stock count '.$count->number,
                    );
                }
            }

            DB::table('inventory_counts')
                ->where('business_id', $business->getKey())
                ->where('id', $countId)
                ->update([
                    'status' => 'posted',
                    'posted_by_user_id' => $actorUserId,
                    'posted_at' => now(),
                    'updated_at' => now(),
                ]);

            $this->countEvent(
                $business,
                $countId,
                $actorUserId,
                'posted',
                'draft',
                'posted',
                ['variance_line_count' => $varianceLines],
            );

            return DB::table('inventory_counts')
                ->where('business_id', $business->getKey())
                ->where('id', $countId)
                ->firstOrFail();
        }, 3);
    }

    public function cancelCount(Business $business, string $countId, string $reason, int $actorUserId): object
    {
        return DB::transaction(function () use ($business, $countId, $reason, $actorUserId): object {
            $this->lockBusiness($business);
            $count = $this->lockedCount($business, $countId);

            if ($count->status === 'cancelled') {
                return DB::table('inventory_counts')
                    ->where('business_id', $business->getKey())
                    ->where('id', $countId)
                    ->firstOrFail();
            }

            if ($count->status !== 'draft') {
                throw ValidationException::withMessages([
                    'count' => 'Only a draft stock count can be cancelled.',
                ]);
            }

            DB::table('inventory_counts')
                ->where('business_id', $business->getKey())
                ->where('id', $countId)
                ->update([
                    'status' => 'cancelled',
                    'cancelled_by_user_id' => $actorUserId,
                    'cancelled_at' => now(),
                    'cancel_reason' => trim($reason),
                    'updated_at' => now(),
                ]);

            $this->countEvent(
                $business,
                $countId,
                $actorUserId,
                'cancelled',
                'draft',
                'cancelled',
                ['reason' => trim($reason)],
            );

            return DB::table('inventory_counts')
                ->where('business_id', $business->getKey())
                ->where('id', $countId)
                ->firstOrFail();
        }, 3);
    }

    private function lockBusiness(Business $business): void
    {
        DB::table('businesses')
            ->where('id', $business->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockedCount(Business $business, string $countId): object
    {
        return DB::table('inventory_counts')
            ->where('business_id', $business->getKey())
            ->where('id', $countId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockedStock(Business $business, string $locationId, string $productId): ?object
    {
        return DB::table('inventory_stocks')
            ->where('business_id', $business->getKey())
            ->where('location_id', $locationId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->first();
    }

    private function writeStock(
        Business $business,
        string $locationId,
        string $productId,
        ?object $stock,
        BigDecimal $quantity,
    ): void {
        $normalized = (string) $quantity->toScale(self::SCALE, RoundingMode::HALF_UP);

        if ($stock) {
            DB::table('inventory_stocks')
                ->where('business_id', $business->getKey())
                ->where('id', $stock->id)
                ->update([
                    'quantity_on_hand' => $normalized,
                    'updated_at' => now(),
                ]);

            return;
        }

        if ($quantity->isZero()) {
            return;
        }

        DB::table('inventory_stocks')->insert([
            'id' => (string) Str::ulid(),
            'business_id' => $business->getKey(),
            'location_id' => $locationId,
            'product_id' => $productId,
            'quantity_on_hand' => $normalized,
            'reorder_level' => '0.0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function movement(
        Business $business,
        string $locationId,
        string $productId,
        int $actorUserId,
        string $type,
        string $quantityDelta,
        string $referenceType,
        string $referenceId,
        string $note,
    ): void {
        DB::table('inventory_movements')->insert([
            'id' => (string) Str::ulid(),
            'business_id' => $business->getKey(),
            'location_id' => $locationId,
            'product_id' => $productId,
            'created_by_user_id' => $actorUserId,
            'type' => $type,
            'quantity_delta' => $quantityDelta,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'note' => $note,
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function transferSnapshot(array $data): array
    {
        return [
            'source_location_id' => (string) $data['source_location_id'],
            'destination_location_id' => (string) $data['destination_location_id'],
            'note' => trim((string) $data['note']),
            'items' => collect($data['items'])
                ->map(fn (array $item): array => [
                    'product_id' => (string) $item['product_id'],
                    'quantity' => (string) BigDecimal::of((string) $item['quantity'])
                        ->toScale(self::SCALE, RoundingMode::HALF_UP),
                ])
                ->sortBy('product_id')
                ->values()
                ->all(),
        ];
    }

    private function countEvent(
        Business $business,
        string $countId,
        ?int $actorUserId,
        string $event,
        ?string $previousStatus,
        string $newStatus,
        ?array $metadata = null,
    ): void {
        DB::table('inventory_count_events')->insert([
            'id' => (string) Str::ulid(),
            'business_id' => $business->getKey(),
            'inventory_count_id' => $countId,
            'actor_user_id' => $actorUserId,
            'event' => $event,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'metadata' => $metadata === null ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function nextNumber(
        Business $business,
        CarbonImmutable $businessNow,
        string $documentType,
        string $prefix,
    ): string {
        $businessDate = $businessNow->toDateString();

        DB::statement(
            'INSERT INTO business_inventory_counters (business_id, business_date, document_type, last_number, created_at, updated_at)
             VALUES (?, ?, ?, LAST_INSERT_ID(1), UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1), updated_at = UTC_TIMESTAMP()',
            [$business->getKey(), $businessDate, $documentType],
        );

        $sequence = (int) DB::selectOne('SELECT LAST_INSERT_ID() AS sequence')->sequence;

        return sprintf('%s-%s-%04d', $prefix, $businessNow->format('Ymd'), $sequence);
    }

    private function positiveDecimal(mixed $value, string $field): string
    {
        $decimal = BigDecimal::of((string) $value);

        if ($decimal->isLessThanOrEqualTo(BigDecimal::zero())) {
            throw ValidationException::withMessages([$field => 'Value must be greater than zero.']);
        }

        return (string) $decimal->toScale(self::SCALE, RoundingMode::HALF_UP);
    }

    private function nonNegativeDecimal(mixed $value, string $field): string
    {
        $decimal = BigDecimal::of((string) $value);

        if ($decimal->isNegative()) {
            throw ValidationException::withMessages([$field => 'Value cannot be negative.']);
        }

        return (string) $decimal->toScale(self::SCALE, RoundingMode::HALF_UP);
    }
}
