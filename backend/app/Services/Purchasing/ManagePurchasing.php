<?php

namespace App\Services\Purchasing;

use App\Models\Business;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ManagePurchasing
{
    private const SCALE = 4;
    private const OPEN_STATUSES = ['draft', 'ordered', 'partially_received'];

    public function saveSupplier(Business $business, array $data, int $actorUserId): object
    {
        return DB::transaction(function () use ($business, $data, $actorUserId): object {
            $this->lockBusiness($business);

            $supplier = null;
            if (! empty($data['id'])) {
                $supplier = DB::table('suppliers')
                    ->where('business_id', $business->getKey())
                    ->where('id', $data['id'])
                    ->lockForUpdate()
                    ->first();

                abort_unless($supplier, 404);
            }

            $this->assertUniqueSupplier(
                $business,
                trim($data['name']),
                $data['tax_number'] ?? null,
                $supplier?->id,
            );

            $payload = [
                'name' => trim($data['name']),
                'tax_number' => $data['tax_number'] ?? null,
                'contact_name' => $data['contact_name'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'address' => $data['address'] ?? null,
                'updated_at' => now(),
            ];

            if ($supplier) {
                DB::table('suppliers')
                    ->where('business_id', $business->getKey())
                    ->where('id', $supplier->id)
                    ->update($payload);

                return $this->supplier($business, (string) $supplier->id);
            }

            $id = (string) Str::ulid();
            DB::table('suppliers')->insert([
                'id' => $id,
                'business_id' => $business->getKey(),
                ...$payload,
                'is_active' => true,
                'created_at' => now(),
            ]);

            return $this->supplier($business, $id);
        }, 3);
    }

    public function setSupplierStatus(Business $business, string $supplierId, bool $isActive): object
    {
        return DB::transaction(function () use ($business, $supplierId, $isActive): object {
            $this->lockBusiness($business);

            $supplier = DB::table('suppliers')
                ->where('business_id', $business->getKey())
                ->where('id', $supplierId)
                ->lockForUpdate()
                ->first();

            abort_unless($supplier, 404);

            if ((bool) $supplier->is_active === $isActive) {
                return $this->normalizeSupplier($supplier);
            }

            if (! $isActive) {
                $openOrders = DB::table('purchase_orders')
                    ->where('business_id', $business->getKey())
                    ->where('supplier_id', $supplierId)
                    ->whereIn('status', self::OPEN_STATUSES)
                    ->exists();

                if ($openOrders) {
                    throw ValidationException::withMessages([
                        'supplier' => 'Complete or cancel every open purchase order before disabling this supplier.',
                    ]);
                }
            }

            DB::table('suppliers')
                ->where('business_id', $business->getKey())
                ->where('id', $supplierId)
                ->update([
                    'is_active' => $isActive,
                    'updated_at' => now(),
                ]);

            return $this->supplier($business, $supplierId);
        }, 3);
    }

    public function createPurchaseOrder(Business $business, array $data, int $actorUserId): object
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

            $supplier = DB::table('suppliers')
                ->where('business_id', $business->getKey())
                ->where('id', $data['supplier_id'])
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (! $supplier) {
                throw ValidationException::withMessages([
                    'supplier_id' => 'Select an active supplier from this business.',
                ]);
            }

            $items = collect($data['items'])->values();
            $productIds = $items->pluck('product_id')->values();

            $products = DB::table('products')
                ->where('business_id', $business->getKey())
                ->whereIn('id', $productIds)
                ->where('is_active', true)
                ->where('tracks_stock', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($products->count() !== $productIds->count()) {
                throw ValidationException::withMessages([
                    'items' => 'Every purchase line must reference an active stock-tracked product in this business.',
                ]);
            }

            $businessNow = CarbonImmutable::now($business->timezone);
            $number = $this->nextNumber($business, $businessNow, 'purchase_order', 'PO');
            $purchaseOrderId = (string) Str::ulid();
            $total = BigDecimal::zero();

            DB::table('purchase_orders')->insert([
                'id' => $purchaseOrderId,
                'business_id' => $business->getKey(),
                'location_id' => $location->id,
                'supplier_id' => $supplier->id,
                'created_by_user_id' => $actorUserId,
                'supplier_name_snapshot' => $supplier->name,
                'supplier_tax_number_snapshot' => $supplier->tax_number,
                'number' => $number,
                'status' => 'draft',
                'currency' => $business->currency,
                'total_cost' => '0.0000',
                'notes' => isset($data['notes']) && trim((string) $data['notes']) !== ''
                    ? trim((string) $data['notes'])
                    : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($items as $item) {
                $product = $products->get($item['product_id']);
                $quantity = $this->positiveDecimal($item['quantity_ordered'], 'quantity_ordered');
                $unitCost = $this->nonNegativeDecimal($item['unit_cost'], 'unit_cost');
                $lineTotal = BigDecimal::of($quantity)
                    ->multipliedBy($unitCost)
                    ->toScale(self::SCALE, RoundingMode::HALF_UP);

                $total = $total->plus($lineTotal);

                DB::table('purchase_order_items')->insert([
                    'id' => (string) Str::ulid(),
                    'business_id' => $business->getKey(),
                    'purchase_order_id' => $purchaseOrderId,
                    'product_id' => $product->id,
                    'product_name_snapshot' => $product->name,
                    'sku_snapshot' => $product->sku,
                    'quantity_ordered' => $quantity,
                    'quantity_received' => '0.0000',
                    'unit_cost' => $unitCost,
                    'line_total' => (string) $lineTotal,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('purchase_orders')
                ->where('business_id', $business->getKey())
                ->where('id', $purchaseOrderId)
                ->update([
                    'total_cost' => (string) $total->toScale(self::SCALE, RoundingMode::HALF_UP),
                    'updated_at' => now(),
                ]);

            $this->event(
                $business,
                $purchaseOrderId,
                $actorUserId,
                'created',
                null,
                'draft',
                ['number' => $number, 'line_count' => $items->count()],
            );

            return $this->purchaseOrder($business, $purchaseOrderId);
        }, 3);
    }

    public function updateDraft(Business $business, string $purchaseOrderId, array $data, int $actorUserId): object
    {
        return DB::transaction(function () use ($business, $purchaseOrderId, $data, $actorUserId): object {
            $this->lockBusiness($business);
            $order = $this->lockedPurchaseOrder($business, $purchaseOrderId);

            if ($order->status !== 'draft') {
                throw ValidationException::withMessages([
                    'purchase_order' => 'Only a draft purchase order can be edited.',
                ]);
            }

            if ((string) $order->location_id !== (string) $data['location_id']) {
                throw ValidationException::withMessages([
                    'location_id' => 'A draft purchase order cannot be moved to another location.',
                ]);
            }

            $location = DB::table('locations')
                ->where('business_id', $business->getKey())
                ->where('id', $order->location_id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (! $location) {
                throw ValidationException::withMessages([
                    'location_id' => 'Reactivate the receiving location before editing this draft.',
                ]);
            }

            $supplier = DB::table('suppliers')
                ->where('business_id', $business->getKey())
                ->where('id', $data['supplier_id'])
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (! $supplier) {
                throw ValidationException::withMessages([
                    'supplier_id' => 'Select an active supplier from this business.',
                ]);
            }

            $items = collect($data['items'])->values();
            $productIds = $items->pluck('product_id')->values();

            $products = DB::table('products')
                ->where('business_id', $business->getKey())
                ->whereIn('id', $productIds)
                ->where('is_active', true)
                ->where('tracks_stock', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($products->count() !== $productIds->count()) {
                throw ValidationException::withMessages([
                    'items' => 'Every purchase line must reference an active stock-tracked product in this business.',
                ]);
            }

            $before = [
                'supplier_id' => $order->supplier_id,
                'supplier_name_snapshot' => $order->supplier_name_snapshot,
                'total_cost' => (string) $order->total_cost,
                'line_count' => DB::table('purchase_order_items')
                    ->where('business_id', $business->getKey())
                    ->where('purchase_order_id', $purchaseOrderId)
                    ->count(),
            ];

            DB::table('purchase_order_items')
                ->where('business_id', $business->getKey())
                ->where('purchase_order_id', $purchaseOrderId)
                ->delete();

            $total = BigDecimal::zero();

            foreach ($items as $item) {
                $product = $products->get($item['product_id']);
                $quantity = $this->positiveDecimal($item['quantity_ordered'], 'quantity_ordered');
                $unitCost = $this->nonNegativeDecimal($item['unit_cost'], 'unit_cost');
                $lineTotal = BigDecimal::of($quantity)
                    ->multipliedBy($unitCost)
                    ->toScale(self::SCALE, RoundingMode::HALF_UP);

                $total = $total->plus($lineTotal);

                DB::table('purchase_order_items')->insert([
                    'id' => (string) Str::ulid(),
                    'business_id' => $business->getKey(),
                    'purchase_order_id' => $purchaseOrderId,
                    'product_id' => $product->id,
                    'product_name_snapshot' => $product->name,
                    'sku_snapshot' => $product->sku,
                    'quantity_ordered' => $quantity,
                    'quantity_received' => '0.0000',
                    'unit_cost' => $unitCost,
                    'line_total' => (string) $lineTotal,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $newTotal = (string) $total->toScale(self::SCALE, RoundingMode::HALF_UP);

            DB::table('purchase_orders')
                ->where('business_id', $business->getKey())
                ->where('id', $purchaseOrderId)
                ->update([
                    'supplier_id' => $supplier->id,
                    'supplier_name_snapshot' => $supplier->name,
                    'supplier_tax_number_snapshot' => $supplier->tax_number,
                    'total_cost' => $newTotal,
                    'notes' => isset($data['notes']) && trim((string) $data['notes']) !== ''
                        ? trim((string) $data['notes'])
                        : null,
                    'updated_at' => now(),
                ]);

            $this->event(
                $business,
                $purchaseOrderId,
                $actorUserId,
                'draft_updated',
                'draft',
                'draft',
                [
                    'previous_supplier_id' => $before['supplier_id'],
                    'supplier_id' => $supplier->id,
                    'previous_total_cost' => $before['total_cost'],
                    'total_cost' => $newTotal,
                    'previous_line_count' => $before['line_count'],
                    'line_count' => $items->count(),
                ],
            );

            return $this->purchaseOrder($business, $purchaseOrderId);
        }, 3);
    }

    public function place(Business $business, string $purchaseOrderId, int $actorUserId): object
    {
        return DB::transaction(function () use ($business, $purchaseOrderId, $actorUserId): object {
            $this->lockBusiness($business);
            $order = $this->lockedPurchaseOrder($business, $purchaseOrderId);

            if ($order->status !== 'draft') {
                throw ValidationException::withMessages([
                    'purchase_order' => 'Only a draft purchase order can be placed.',
                ]);
            }

            $supplierActive = DB::table('suppliers')
                ->where('business_id', $business->getKey())
                ->where('id', $order->supplier_id)
                ->where('is_active', true)
                ->exists();

            $locationActive = DB::table('locations')
                ->where('business_id', $business->getKey())
                ->where('id', $order->location_id)
                ->where('is_active', true)
                ->exists();

            if (! $supplierActive || ! $locationActive) {
                throw ValidationException::withMessages([
                    'purchase_order' => 'The supplier and receiving location must both be active before placing this order.',
                ]);
            }

            $items = DB::table('purchase_order_items')
                ->where('business_id', $business->getKey())
                ->where('purchase_order_id', $purchaseOrderId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    'purchase_order' => 'A purchase order must contain at least one line.',
                ]);
            }

            $stockProducts = DB::table('products')
                ->where('business_id', $business->getKey())
                ->whereIn('id', $items->pluck('product_id'))
                ->where('is_active', true)
                ->where('tracks_stock', true)
                ->count();

            if ($stockProducts !== $items->count()) {
                throw ValidationException::withMessages([
                    'purchase_order' => 'Every line must still reference an active stock-tracked product before placement.',
                ]);
            }

            DB::table('purchase_orders')
                ->where('business_id', $business->getKey())
                ->where('id', $purchaseOrderId)
                ->update([
                    'status' => 'ordered',
                    'placed_by_user_id' => $actorUserId,
                    'ordered_at' => now(),
                    'updated_at' => now(),
                ]);

            $this->event($business, $purchaseOrderId, $actorUserId, 'placed', 'draft', 'ordered');

            return $this->purchaseOrder($business, $purchaseOrderId);
        }, 3);
    }

    public function cancel(Business $business, string $purchaseOrderId, string $reason, int $actorUserId): object
    {
        return DB::transaction(function () use ($business, $purchaseOrderId, $reason, $actorUserId): object {
            $this->lockBusiness($business);
            $order = $this->lockedPurchaseOrder($business, $purchaseOrderId);

            if (! in_array($order->status, ['draft', 'ordered'], true)) {
                throw ValidationException::withMessages([
                    'purchase_order' => 'Only an unreceived draft or ordered purchase order can be cancelled.',
                ]);
            }

            $received = DB::table('purchase_order_items')
                ->where('business_id', $business->getKey())
                ->where('purchase_order_id', $purchaseOrderId)
                ->where('quantity_received', '>', 0)
                ->exists();

            if ($received) {
                throw ValidationException::withMessages([
                    'purchase_order' => 'A purchase order with received goods cannot be cancelled.',
                ]);
            }

            $previous = $order->status;

            DB::table('purchase_orders')
                ->where('business_id', $business->getKey())
                ->where('id', $purchaseOrderId)
                ->update([
                    'status' => 'cancelled',
                    'cancelled_by_user_id' => $actorUserId,
                    'cancelled_at' => now(),
                    'cancel_reason' => trim($reason),
                    'updated_at' => now(),
                ]);

            $this->event(
                $business,
                $purchaseOrderId,
                $actorUserId,
                'cancelled',
                $previous,
                'cancelled',
                ['reason' => trim($reason)],
            );

            return $this->purchaseOrder($business, $purchaseOrderId);
        }, 3);
    }

    public function receive(Business $business, string $purchaseOrderId, array $data, int $actorUserId): object
    {
        return DB::transaction(function () use ($business, $purchaseOrderId, $data, $actorUserId): object {
            $this->lockBusiness($business);
            $order = $this->lockedPurchaseOrder($business, $purchaseOrderId);

            if (! in_array($order->status, ['ordered', 'partially_received'], true)) {
                throw ValidationException::withMessages([
                    'purchase_order' => 'Goods can only be received against an ordered purchase order.',
                ]);
            }

            $locationActive = DB::table('locations')
                ->where('business_id', $business->getKey())
                ->where('id', $order->location_id)
                ->where('is_active', true)
                ->exists();

            if (! $locationActive) {
                throw ValidationException::withMessages([
                    'purchase_order' => 'Reactivate the receiving location before posting this goods receipt.',
                ]);
            }

            $requested = collect($data['items'])->keyBy('purchase_order_item_id');
            $lines = DB::table('purchase_order_items')
                ->where('business_id', $business->getKey())
                ->where('purchase_order_id', $purchaseOrderId)
                ->whereIn('id', $requested->keys())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($lines->count() !== $requested->count()) {
                throw ValidationException::withMessages([
                    'items' => 'One or more receipt lines do not belong to this purchase order.',
                ]);
            }

            $receiptId = (string) Str::ulid();
            $businessNow = CarbonImmutable::now($business->timezone);
            $receiptNumber = $this->nextNumber($business, $businessNow, 'goods_receipt', 'GRN');

            DB::table('goods_receipts')->insert([
                'id' => $receiptId,
                'business_id' => $business->getKey(),
                'location_id' => $order->location_id,
                'purchase_order_id' => $purchaseOrderId,
                'received_by_user_id' => $actorUserId,
                'number' => $receiptNumber,
                'note' => isset($data['note']) && trim((string) $data['note']) !== ''
                    ? trim((string) $data['note'])
                    : null,
                'received_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $receiptTotal = BigDecimal::zero();

            foreach ($lines as $line) {
                $payload = $requested->get($line->id);
                $quantity = BigDecimal::of(
                    $this->positiveDecimal($payload['quantity_received'], 'quantity_received')
                )->toScale(self::SCALE, RoundingMode::HALF_UP);
                $ordered = BigDecimal::of((string) $line->quantity_ordered)->toScale(self::SCALE, RoundingMode::HALF_UP);
                $alreadyReceived = BigDecimal::of((string) $line->quantity_received)->toScale(self::SCALE, RoundingMode::HALF_UP);
                $remaining = $ordered->minus($alreadyReceived);

                if ($quantity->isGreaterThan($remaining)) {
                    throw ValidationException::withMessages([
                        'items' => "Receipt quantity exceeds the remaining quantity for {$line->product_name_snapshot}.",
                    ]);
                }

                $unitCost = BigDecimal::of((string) $line->unit_cost)->toScale(self::SCALE, RoundingMode::HALF_UP);
                $lineTotal = $quantity->multipliedBy($unitCost)->toScale(self::SCALE, RoundingMode::HALF_UP);
                $receiptTotal = $receiptTotal->plus($lineTotal);

                DB::table('goods_receipt_items')->insert([
                    'id' => (string) Str::ulid(),
                    'business_id' => $business->getKey(),
                    'goods_receipt_id' => $receiptId,
                    'purchase_order_item_id' => $line->id,
                    'product_id' => $line->product_id,
                    'quantity_received' => (string) $quantity,
                    'unit_cost_snapshot' => (string) $unitCost,
                    'line_total' => (string) $lineTotal,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('purchase_order_items')
                    ->where('business_id', $business->getKey())
                    ->where('id', $line->id)
                    ->update([
                        'quantity_received' => (string) $alreadyReceived->plus($quantity)->toScale(self::SCALE, RoundingMode::HALF_UP),
                        'updated_at' => now(),
                    ]);

                $stock = DB::table('inventory_stocks')
                    ->where('business_id', $business->getKey())
                    ->where('location_id', $order->location_id)
                    ->where('product_id', $line->product_id)
                    ->lockForUpdate()
                    ->first();

                $current = BigDecimal::of((string) ($stock->quantity_on_hand ?? '0'))
                    ->toScale(self::SCALE, RoundingMode::HALF_UP);
                $next = $current->plus($quantity)->toScale(self::SCALE, RoundingMode::HALF_UP);

                if ($stock) {
                    DB::table('inventory_stocks')
                        ->where('id', $stock->id)
                        ->update([
                            'quantity_on_hand' => (string) $next,
                            'updated_at' => now(),
                        ]);
                } else {
                    DB::table('inventory_stocks')->insert([
                        'id' => (string) Str::ulid(),
                        'business_id' => $business->getKey(),
                        'location_id' => $order->location_id,
                        'product_id' => $line->product_id,
                        'quantity_on_hand' => (string) $next,
                        'reorder_level' => '0.0000',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::table('inventory_movements')->insert([
                    'id' => (string) Str::ulid(),
                    'business_id' => $business->getKey(),
                    'location_id' => $order->location_id,
                    'product_id' => $line->product_id,
                    'created_by_user_id' => $actorUserId,
                    'type' => 'purchase_receipt',
                    'quantity_delta' => (string) $quantity,
                    'reference_type' => 'goods_receipt',
                    'reference_id' => $receiptId,
                    'note' => 'Received against '.$order->number,
                    'occurred_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $remainingLines = DB::table('purchase_order_items')
                ->where('business_id', $business->getKey())
                ->where('purchase_order_id', $purchaseOrderId)
                ->whereColumn('quantity_received', '<', 'quantity_ordered')
                ->exists();

            $previous = $order->status;
            $nextStatus = $remainingLines ? 'partially_received' : 'received';

            DB::table('purchase_orders')
                ->where('business_id', $business->getKey())
                ->where('id', $purchaseOrderId)
                ->update([
                    'status' => $nextStatus,
                    'updated_at' => now(),
                ]);

            $this->event(
                $business,
                $purchaseOrderId,
                $actorUserId,
                'goods_received',
                $previous,
                $nextStatus,
                [
                    'goods_receipt_id' => $receiptId,
                    'goods_receipt_number' => $receiptNumber,
                    'line_count' => $lines->count(),
                    'receipt_total' => (string) $receiptTotal->toScale(self::SCALE, RoundingMode::HALF_UP),
                ],
            );

            return $this->goodsReceipt($business, $receiptId);
        }, 3);
    }

    private function lockBusiness(Business $business): void
    {
        DB::table('businesses')
            ->where('id', $business->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertUniqueSupplier(Business $business, string $name, ?string $taxNumber, ?string $exceptId): void
    {
        $nameQuery = DB::table('suppliers')
            ->where('business_id', $business->getKey())
            ->whereRaw('LOWER(name) = ?', [Str::lower($name)]);

        if ($exceptId) {
            $nameQuery->where('id', '!=', $exceptId);
        }

        if ($nameQuery->exists()) {
            throw ValidationException::withMessages([
                'name' => 'A supplier with this name already exists in this business.',
            ]);
        }

        if ($taxNumber !== null && $taxNumber !== '') {
            $taxQuery = DB::table('suppliers')
                ->where('business_id', $business->getKey())
                ->whereRaw('LOWER(tax_number) = ?', [Str::lower($taxNumber)]);

            if ($exceptId) {
                $taxQuery->where('id', '!=', $exceptId);
            }

            if ($taxQuery->exists()) {
                throw ValidationException::withMessages([
                    'tax_number' => 'A supplier with this tax number already exists in this business.',
                ]);
            }
        }
    }

    private function supplier(Business $business, string $supplierId): object
    {
        $supplier = DB::table('suppliers')
            ->where('business_id', $business->getKey())
            ->where('id', $supplierId)
            ->firstOrFail();

        return $this->normalizeSupplier($supplier);
    }

    private function normalizeSupplier(object $supplier): object
    {
        $supplier->is_active = (bool) $supplier->is_active;

        return $supplier;
    }

    private function lockedPurchaseOrder(Business $business, string $purchaseOrderId): object
    {
        return DB::table('purchase_orders')
            ->where('business_id', $business->getKey())
            ->where('id', $purchaseOrderId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function purchaseOrder(Business $business, string $purchaseOrderId): object
    {
        return DB::table('purchase_orders')
            ->where('business_id', $business->getKey())
            ->where('id', $purchaseOrderId)
            ->firstOrFail();
    }

    private function goodsReceipt(Business $business, string $receiptId): object
    {
        return DB::table('goods_receipts')
            ->where('business_id', $business->getKey())
            ->where('id', $receiptId)
            ->firstOrFail();
    }

    private function event(
        Business $business,
        string $purchaseOrderId,
        ?int $actorUserId,
        string $event,
        ?string $previousStatus,
        string $newStatus,
        ?array $metadata = null,
    ): void {
        DB::table('purchase_order_events')->insert([
            'id' => (string) Str::ulid(),
            'business_id' => $business->getKey(),
            'purchase_order_id' => $purchaseOrderId,
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
            'INSERT INTO business_purchase_counters (business_id, business_date, document_type, last_number, created_at, updated_at)
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
