<?php

namespace App\Services\Operations;

use App\Models\Business;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class OperationsService
{
    private const SCALE = 4;

    public function saveProduct(Business $business, array $data, ?int $actorUserId = null): object
    {
        return DB::transaction(function () use ($business, $data, $actorUserId): object {
            DB::table('businesses')
                ->where('id', $business->id)
                ->lockForUpdate()
                ->firstOrFail();

            $existing = null;

            if (! empty($data['id'])) {
                $existing = DB::table('products')
                    ->where('business_id', $business->id)
                    ->where('id', $data['id'])
                    ->lockForUpdate()
                    ->first();

                abort_unless($existing, 404);
            }

            if ($existing && (bool) $existing->tracks_stock && ! (bool) $data['tracks_stock']) {
                $hasOutstandingPurchasing = DB::table('purchase_order_items as poi')
                    ->join('purchase_orders as po', function ($join) use ($business): void {
                        $join->on('po.id', '=', 'poi.purchase_order_id')
                            ->where('po.business_id', $business->id);
                    })
                    ->where('poi.business_id', $business->id)
                    ->where('poi.product_id', $existing->id)
                    ->whereIn('po.status', ['draft', 'ordered', 'partially_received'])
                    ->whereColumn('poi.quantity_received', '<', 'poi.quantity_ordered')
                    ->exists();

                if ($hasOutstandingPurchasing) {
                    throw ValidationException::withMessages([
                        'tracks_stock' => 'Complete or cancel outstanding purchase orders before disabling stock tracking for this product.',
                    ]);
                }

                $hasDraftCount = DB::table('inventory_count_items as ici')
                    ->join('inventory_counts as ic', function ($join) use ($business): void {
                        $join->on('ic.id', '=', 'ici.inventory_count_id')
                            ->where('ic.business_id', $business->id);
                    })
                    ->where('ici.business_id', $business->id)
                    ->where('ici.product_id', $existing->id)
                    ->where('ic.status', 'draft')
                    ->exists();

                if ($hasDraftCount) {
                    throw ValidationException::withMessages([
                        'tracks_stock' => 'Post or cancel open stock counts before disabling stock tracking for this product.',
                    ]);
                }

                $hasPhysicalStock = DB::table('inventory_stocks')
                    ->where('business_id', $business->id)
                    ->where('product_id', $existing->id)
                    ->where('quantity_on_hand', '!=', 0)
                    ->exists();

                if ($hasPhysicalStock) {
                    throw ValidationException::withMessages([
                        'tracks_stock' => 'Bring stock to zero with an audited adjustment or stock count before disabling stock tracking.',
                    ]);
                }
            }

            if (! empty($data['preparation_station'])) {
                $station = DB::table('preparation_stations')
                    ->where('business_id', $business->id)
                    ->where('code', $data['preparation_station'])
                    ->lockForUpdate()
                    ->first();

                $keepsInactiveStationSafely = $station
                    && ! (bool) $station->is_active
                    && $existing
                    && (string) $existing->preparation_station === (string) $station->code
                    && ! (bool) $data['is_active'];

                if (! $station || (! (bool) $station->is_active && ! $keepsInactiveStationSafely)) {
                    throw ValidationException::withMessages([
                        'preparation_station' => 'Select an active preparation station, or keep this product inactive in its existing inactive station.',
                    ]);
                }
            }

            if (! empty($data['category_id'])) {
                $category = DB::table('product_categories')
                    ->where('business_id', $business->id)
                    ->where('id', $data['category_id'])
                    ->lockForUpdate()
                    ->first();

                $keepsInactiveCategorySafely = $category
                    && ! (bool) $category->is_active
                    && $existing
                    && (string) $existing->product_category_id === (string) $category->id
                    && ! (bool) $data['is_active'];

                if (! $category || (! (bool) $category->is_active && ! $keepsInactiveCategorySafely)) {
                    throw ValidationException::withMessages([
                        'category_id' => 'Select an active category, or keep this product inactive in its existing inactive category.',
                    ]);
                }
            }

            $before = $existing ? $this->productState($existing) : null;

            $payload = [
                'business_id' => $business->id,
                'product_category_id' => $data['category_id'] ?? null,
                'name' => trim($data['name']),
                'sku' => isset($data['sku']) && trim((string) $data['sku']) !== '' ? trim((string) $data['sku']) : null,
                'sale_price' => $this->decimal($data['sale_price']),
                'tax_rate' => $this->decimal($data['tax_rate']),
                'unit_code' => trim($data['unit_code']),
                'unit_label' => trim($data['unit_label']),
                'preparation_station' => $data['preparation_station'] ?? null,
                'tracks_stock' => $data['tracks_stock'],
                'is_active' => $data['is_active'],
                'updated_at' => now(),
            ];

            if ($existing) {
                DB::table('products')
                    ->where('business_id', $business->id)
                    ->where('id', $existing->id)
                    ->update($payload);

                $id = (string) $existing->id;
            } else {
                $id = (string) Str::ulid();
                $payload['id'] = $id;
                $payload['created_at'] = now();
                DB::table('products')->insert($payload);
            }

            $product = DB::table('products')
                ->where('business_id', $business->id)
                ->where('id', $id)
                ->first();

            $product->is_active = (bool) $product->is_active;
            $product->tracks_stock = (bool) $product->tracks_stock;

            $after = $this->productState($product);
            if ($actorUserId !== null && ($before === null || $before !== $after)) {
                $this->configurationAudit(
                    $business,
                    $actorUserId,
                    'product',
                    $id,
                    $before === null ? 'created' : 'updated',
                    $before,
                    $after,
                );
            }

            return $product;
        }, attempts: 3);
    }

    public function setProductStatus(Business $business, string $productId, bool $isActive, ?int $actorUserId = null): object
    {
        return DB::transaction(function () use ($business, $productId, $isActive, $actorUserId): object {
            $product = DB::table('products')
                ->where('business_id', $business->id)
                ->where('id', $productId)
                ->lockForUpdate()
                ->first();

            abort_unless($product, 404);

            if (! $isActive) {
                $hasOutstandingPurchasing = DB::table('purchase_order_items as poi')
                    ->join('purchase_orders as po', function ($join) use ($business): void {
                        $join->on('po.id', '=', 'poi.purchase_order_id')
                            ->where('po.business_id', $business->id);
                    })
                    ->where('poi.business_id', $business->id)
                    ->where('poi.product_id', $productId)
                    ->whereIn('po.status', ['draft', 'ordered', 'partially_received'])
                    ->whereColumn('poi.quantity_received', '<', 'poi.quantity_ordered')
                    ->exists();

                if ($hasOutstandingPurchasing) {
                    throw ValidationException::withMessages([
                        'product' => 'Complete or cancel outstanding purchase orders before disabling this product.',
                    ]);
                }
            }

            if ($isActive && $product->preparation_station) {
                $station = DB::table('preparation_stations')
                    ->where('business_id', $business->id)
                    ->where('code', $product->preparation_station)
                    ->lockForUpdate()
                    ->first();

                if (! $station || ! (bool) $station->is_active) {
                    throw ValidationException::withMessages([
                        'product' => 'Reassign this product to an active preparation station, remove its routing, or reactivate the station before enabling it.',
                    ]);
                }
            }

            if ($isActive && $product->product_category_id) {
                $category = DB::table('product_categories')
                    ->where('business_id', $business->id)
                    ->where('id', $product->product_category_id)
                    ->lockForUpdate()
                    ->first();

                if (! $category || ! (bool) $category->is_active) {
                    throw ValidationException::withMessages([
                        'product' => 'Reassign this product to an active category before enabling it.',
                    ]);
                }
            }

            $before = $this->productState($product);

            if ((bool) $product->is_active !== $isActive) {
                DB::table('products')
                    ->where('business_id', $business->id)
                    ->where('id', $productId)
                    ->update(['is_active' => $isActive, 'updated_at' => now()]);
            }

            $updated = DB::table('products')
                ->where('business_id', $business->id)
                ->where('id', $productId)
                ->first();

            $updated->is_active = (bool) $updated->is_active;
            $updated->tracks_stock = (bool) $updated->tracks_stock;

            $after = $this->productState($updated);
            if ($actorUserId !== null && $before !== $after) {
                $this->configurationAudit($business, $actorUserId, 'product', $productId, 'status_changed', $before, $after);
            }

            return $updated;
        }, attempts: 3);
    }

    public function saveCategory(Business $business, array $data, ?int $actorUserId = null): object
    {
        return DB::transaction(function () use ($business, $data, $actorUserId): object {
            DB::table('businesses')->where('id', $business->id)->lockForUpdate()->first();
            $name = trim($data['name']);
            $duplicate = DB::table('product_categories')
                ->where('business_id', $business->id)
                ->whereRaw('LOWER(name) = ?', [Str::lower($name)]);

            if (! empty($data['id'])) {
                $duplicate->where('id', '!=', $data['id']);
            }

            if ($duplicate->exists()) {
                throw ValidationException::withMessages([
                    'name' => 'A category with this name already exists in this business.',
                ]);
            }

            $existingCategory = ! empty($data['id'])
                ? DB::table('product_categories')
                    ->where('business_id', $business->id)
                    ->where('id', $data['id'])
                    ->lockForUpdate()
                    ->first()
                : null;

            if (! empty($data['id'])) {
                abort_unless($existingCategory, 404);
            }

            $before = $existingCategory ? $this->categoryState($existingCategory) : null;

            $payload = [
                'name' => $name,
                'color' => $data['color'] ?? null,
                'sort_order' => (int) $data['sort_order'],
                'updated_at' => now(),
            ];

            if (! empty($data['id'])) {
                DB::table('product_categories')
                    ->where('business_id', $business->id)
                    ->where('id', $existingCategory->id)
                    ->update($payload);
                $id = (string) $existingCategory->id;
            } else {
                $id = (string) Str::ulid();
                DB::table('product_categories')->insert([
                    'id' => $id,
                    'business_id' => $business->id,
                    ...$payload,
                    'is_active' => true,
                    'created_at' => now(),
                ]);
            }

            $category = DB::table('product_categories')
                ->where('business_id', $business->id)
                ->where('id', $id)
                ->first();

            $category->is_active = (bool) $category->is_active;

            $after = $this->categoryState($category);
            if ($actorUserId !== null && ($before === null || $before !== $after)) {
                $this->configurationAudit(
                    $business,
                    $actorUserId,
                    'product_category',
                    $id,
                    $before === null ? 'created' : 'updated',
                    $before,
                    $after,
                );
            }

            return $category;
        }, attempts: 3);
    }

    public function setCategoryStatus(Business $business, string $categoryId, bool $isActive, ?int $actorUserId = null): object
    {
        return DB::transaction(function () use ($business, $categoryId, $isActive, $actorUserId): object {
            $category = DB::table('product_categories')
                ->where('business_id', $business->id)
                ->where('id', $categoryId)
                ->lockForUpdate()
                ->first();

            abort_unless($category, 404);

            if (! $isActive) {
                $hasActiveProducts = DB::table('products')
                    ->where('business_id', $business->id)
                    ->where('product_category_id', $categoryId)
                    ->where('is_active', true)
                    ->exists();

                if ($hasActiveProducts) {
                    throw ValidationException::withMessages([
                        'category' => 'Move or disable active products before disabling this category.',
                    ]);
                }
            }

            $before = $this->categoryState($category);

            if ((bool) $category->is_active !== $isActive) {
                DB::table('product_categories')
                    ->where('business_id', $business->id)
                    ->where('id', $categoryId)
                    ->update([
                        'is_active' => $isActive,
                        'updated_at' => now(),
                    ]);
            }

            $updated = DB::table('product_categories')
                ->where('business_id', $business->id)
                ->where('id', $categoryId)
                ->first();

            $updated->is_active = (bool) $updated->is_active;

            $after = $this->categoryState($updated);
            if ($actorUserId !== null && $before !== $after) {
                $this->configurationAudit($business, $actorUserId, 'product_category', $categoryId, 'status_changed', $before, $after);
            }

            return $updated;
        }, attempts: 3);
    }

    public function adjustInventory(Business $business, string $locationId, array $data, int $userId): void
    {
        DB::transaction(function () use ($business, $locationId, $data, $userId): void {
            DB::table('businesses')
                ->where('id', $business->id)
                ->lockForUpdate()
                ->firstOrFail();

            $delta = BigDecimal::of((string) $data['quantity_delta'])->toScale(self::SCALE, RoundingMode::HALF_UP);
            if ($delta->isZero()) {
                throw ValidationException::withMessages([
                    'quantity_delta' => 'Quantity adjustment cannot be zero.',
                ]);
            }

            $snapshot = [
                'location_id' => $locationId,
                'product_id' => (string) $data['product_id'],
                'quantity_delta' => (string) $delta,
                'note' => isset($data['note']) && trim((string) $data['note']) !== ''
                    ? trim((string) $data['note'])
                    : null,
            ];

            $existingMovement = DB::table('inventory_movements')
                ->where('business_id', $business->id)
                ->where('idempotency_key', $data['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($existingMovement) {
                $stored = $existingMovement->request_snapshot
                    ? json_decode($existingMovement->request_snapshot, true, 512, JSON_THROW_ON_ERROR)
                    : null;

                if ($existingMovement->type !== 'adjustment' || $stored !== $snapshot) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => 'This idempotency key was already used for a different inventory adjustment request.',
                    ]);
                }

                return;
            }

            $product = DB::table('products')
                ->where('business_id', $business->id)
                ->where('id', $data['product_id'])
                ->lockForUpdate()
                ->first();

            if (! $product || ! (bool) $product->tracks_stock) {
                throw ValidationException::withMessages([
                    'product_id' => 'Select a stock-tracked product from this business.',
                ]);
            }

            $stock = DB::table('inventory_stocks')
                ->where([
                    'business_id' => $business->id,
                    'location_id' => $locationId,
                    'product_id' => $data['product_id'],
                ])
                ->lockForUpdate()
                ->first();

            $current = BigDecimal::of((string) ($stock->quantity_on_hand ?? '0'))
                ->toScale(self::SCALE, RoundingMode::HALF_UP);
            $next = $current->plus($delta)->toScale(self::SCALE, RoundingMode::HALF_UP);

            if ($next->isNegative()) {
                throw ValidationException::withMessages([
                    'quantity_delta' => 'This adjustment would make stock negative. Count the item and enter the verified correction instead.',
                ]);
            }

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
                    'business_id' => $business->id,
                    'location_id' => $locationId,
                    'product_id' => $data['product_id'],
                    'quantity_on_hand' => (string) $next,
                    'reorder_level' => '0.0000',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('inventory_movements')->insert([
                'id' => (string) Str::ulid(),
                'business_id' => $business->id,
                'location_id' => $locationId,
                'product_id' => $data['product_id'],
                'created_by_user_id' => $userId,
                'type' => 'adjustment',
                'quantity_delta' => (string) $delta,
                'idempotency_key' => $data['idempotency_key'],
                'request_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'note' => $snapshot['note'],
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, attempts: 3);
    }

    public function createExpense(Business $business, array $data, int $userId): object
    {
        return DB::transaction(function () use ($business, $data, $userId): object {
            DB::table('businesses')
                ->where('id', $business->id)
                ->lockForUpdate()
                ->firstOrFail();

            $amount = BigDecimal::of((string) $data['amount'])->toScale(self::SCALE, RoundingMode::HALF_UP);
            $snapshot = [
                'location_id' => $data['location_id'] ?? null,
                'category' => trim((string) $data['category']),
                'description' => trim((string) $data['description']),
                'amount' => (string) $amount,
                'expense_date' => (string) $data['expense_date'],
            ];

            $existing = DB::table('expenses')
                ->where('business_id', $business->id)
                ->where('idempotency_key', $data['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $stored = $existing->request_snapshot
                    ? json_decode($existing->request_snapshot, true, 512, JSON_THROW_ON_ERROR)
                    : null;

                if ($existing->reversal_of_expense_id !== null || $stored !== $snapshot) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => 'This idempotency key was already used for a different expense request.',
                    ]);
                }

                return $existing;
            }

            $id = (string) Str::ulid();

            DB::table('expenses')->insert([
                'id' => $id,
                'business_id' => $business->id,
                'location_id' => $snapshot['location_id'],
                'created_by_user_id' => $userId,
                'category' => $snapshot['category'],
                'description' => $snapshot['description'],
                'amount' => (string) $amount,
                'currency' => $business->currency,
                'expense_date' => $snapshot['expense_date'],
                'status' => 'posted',
                'idempotency_key' => $data['idempotency_key'],
                'request_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return DB::table('expenses')
                ->where('business_id', $business->id)
                ->where('id', $id)
                ->firstOrFail();
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
        $expenseFrom = $from->copy()->setTimezone($business->timezone)->toDateString();
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

    public function updateBusinessProfile(Business $business, array $data, int $actorUserId): object
    {
        return DB::transaction(function () use ($business, $data, $actorUserId): object {
            $record = DB::table('businesses')
                ->where('id', $business->id)
                ->lockForUpdate()
                ->firstOrFail();

            $before = [
                'name' => $record->name,
                'legal_name' => $record->legal_name,
                'tax_number' => $record->tax_number,
                'currency' => $record->currency,
                'timezone' => $record->timezone,
            ];

            $currencyChanged = $record->currency !== $data['currency'];
            $timezoneChanged = $record->timezone !== $data['timezone'];
            $taxNumberChanged = (string) ($record->tax_number ?? '') !== (string) ($data['tax_number'] ?? '');

            $productionFiscalization = DB::table('fiscalization_profiles')
                ->where('business_id', $business->id)
                ->whereNotNull('production_activated_at')
                ->exists();

            if (($currencyChanged || $timezoneChanged) && $productionFiscalization) {
                throw ValidationException::withMessages([
                    $currencyChanged ? 'currency' : 'timezone' => 'Currency and timezone cannot change after production fiscalization activation without a dedicated migration workflow.',
                ]);
            }

            if ($currencyChanged || $timezoneChanged) {
                $transactionalHistory = DB::table('orders')->where('business_id', $business->id)->exists()
                    || DB::table('payments')->where('business_id', $business->id)->exists()
                    || DB::table('expenses')->where('business_id', $business->id)->exists()
                    || DB::table('invoices')->where('business_id', $business->id)->exists()
                    || DB::table('purchase_orders')->where('business_id', $business->id)->exists()
                    || DB::table('inventory_movements')->where('business_id', $business->id)->exists();

                if ($transactionalHistory) {
                    throw ValidationException::withMessages([
                        $currencyChanged ? 'currency' : 'timezone' => 'Currency and timezone are locked after transactional history exists. Use a controlled migration workflow instead.',
                    ]);
                }
            }

            if ($taxNumberChanged) {
                if ($productionFiscalization) {
                    throw ValidationException::withMessages([
                        'tax_number' => 'Tax number cannot be changed after production fiscalization activation without a dedicated fiscal migration workflow.',
                    ]);
                }
            }

            $after = [
                'name' => trim($data['name']),
                'legal_name' => $data['legal_name'] ?? null,
                'tax_number' => $data['tax_number'] ?? null,
                'currency' => $data['currency'],
                'timezone' => $data['timezone'],
            ];

            if ($before !== $after) {
                DB::table('businesses')->where('id', $business->id)->update([
                    ...$after,
                    'updated_at' => now(),
                ]);

                DB::table('fiscalization_profiles')
                    ->where('business_id', $business->id)
                    ->update([
                        'preflight_checked_at' => null,
                        'preflight_status' => null,
                        'updated_at' => now(),
                    ]);

                $this->configurationAudit(
                    $business,
                    $actorUserId,
                    'business_profile',
                    (string) $business->id,
                    'updated',
                    $before,
                    $after,
                );
            }

            return DB::table('businesses')->where('id', $business->id)->firstOrFail();
        }, attempts: 3);
    }

    public function updateSettings(Business $business, array $data, ?int $actorUserId = null): void
    {
        DB::transaction(function () use ($business, $data, $actorUserId): void {
            DB::table('businesses')->where('id', $business->id)->lockForUpdate()->firstOrFail();

            $before = [];
            foreach (array_keys($data) as $key) {
                $stored = DB::table('business_settings')
                    ->where('business_id', $business->id)
                    ->where('key', $key)
                    ->lockForUpdate()
                    ->first();
                $before[$key] = $stored ? json_decode($stored->value, true) : null;
            }

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

            if ($actorUserId !== null && $before !== $data) {
                $this->configurationAudit(
                    $business,
                    $actorUserId,
                    'business_settings',
                    (string) $business->id,
                    'updated',
                    $before,
                    $data,
                );
            }
        }, attempts: 3);
    }

    private function decimal(string|int|BigDecimal $value): string
    {
        return (string) BigDecimal::of((string) $value)->toScale(self::SCALE, RoundingMode::HALF_UP);
    }

    private function productState(object $product): array
    {
        return [
            'product_category_id' => $product->product_category_id,
            'name' => $product->name,
            'sku' => $product->sku,
            'sale_price' => $this->decimal($product->sale_price),
            'tax_rate' => $this->decimal($product->tax_rate),
            'unit_code' => $product->unit_code,
            'unit_label' => $product->unit_label,
            'preparation_station' => $product->preparation_station,
            'tracks_stock' => (bool) $product->tracks_stock,
            'is_active' => (bool) $product->is_active,
        ];
    }

    private function categoryState(object $category): array
    {
        return [
            'name' => $category->name,
            'color' => $category->color,
            'sort_order' => (int) $category->sort_order,
            'is_active' => (bool) $category->is_active,
        ];
    }

    private function configurationAudit(
        Business $business,
        int $actorUserId,
        string $entityType,
        string $entityId,
        string $action,
        ?array $before,
        ?array $after,
    ): void {
        DB::table('business_configuration_audits')->insert([
            'id' => (string) Str::ulid(),
            'business_id' => $business->id,
            'location_id' => null,
            'performed_by_user_id' => $actorUserId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'previous_state' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'new_state' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR),
            'performed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

}
