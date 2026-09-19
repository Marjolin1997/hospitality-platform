<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CancelPurchaseOrderRequest;
use App\Http\Requests\Api\V1\CreatePurchaseOrderRequest;
use App\Http\Requests\Api\V1\ReceivePurchaseOrderRequest;
use App\Http\Requests\Api\V1\SaveSupplierRequest;
use App\Http\Requests\Api\V1\SetSupplierStatusRequest;
use App\Models\Business;
use App\Services\Purchasing\ManagePurchasing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PurchasingController extends Controller
{
    public function __construct(private readonly ManagePurchasing $purchasing) {}

    public function suppliers(): JsonResponse
    {
        $business = app(Business::class);

        $rows = DB::table('suppliers as s')
            ->where('s.business_id', $business->getKey())
            ->select(
                's.id',
                's.name',
                's.tax_number',
                's.contact_name',
                's.email',
                's.phone',
                's.address',
                's.is_active',
            )
            ->selectSub(function ($query): void {
                $query->from('purchase_orders')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('purchase_orders.supplier_id', 's.id')
                    ->whereColumn('purchase_orders.business_id', 's.business_id')
                    ->whereIn('purchase_orders.status', ['draft', 'ordered', 'partially_received']);
            }, 'open_order_count')
            ->orderByDesc('s.is_active')
            ->orderBy('s.name')
            ->get()
            ->map(function (object $supplier): object {
                $supplier->is_active = (bool) $supplier->is_active;
                $supplier->open_order_count = (int) $supplier->open_order_count;

                return $supplier;
            });

        return response()->json(['data' => $rows]);
    }

    public function saveSupplier(SaveSupplierRequest $request): JsonResponse
    {
        $supplier = $this->purchasing->saveSupplier(
            app(Business::class),
            $request->validated(),
            (int) $request->user()->id,
        );

        return response()->json(
            ['data' => $supplier],
            $request->filled('id') ? 200 : 201,
        );
    }

    public function setSupplierStatus(SetSupplierStatusRequest $request, string $supplier): JsonResponse
    {
        return response()->json([
            'data' => $this->purchasing->setSupplierStatus(
                app(Business::class),
                $supplier,
                (bool) $request->validated('is_active'),
            ),
        ]);
    }

    public function options(Request $request): JsonResponse
    {
        $business = app(Business::class);
        $locationId = $this->activeLocationId($request, $business);

        return response()->json([
            'data' => [
                'location_id' => $locationId,
                'suppliers' => DB::table('suppliers')
                    ->where('business_id', $business->getKey())
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'name', 'tax_number']),
                'products' => DB::table('products')
                    ->where('business_id', $business->getKey())
                    ->where('is_active', true)
                    ->where('tracks_stock', true)
                    ->orderBy('name')
                    ->get(['id', 'name', 'sku', 'unit_label']),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $business = app(Business::class);
        $locationId = $this->activeLocationId($request, $business);

        $rows = DB::table('purchase_orders as po')
            ->where('po.business_id', $business->getKey())
            ->where('po.location_id', $locationId)
            ->select(
                'po.id',
                'po.number',
                'po.supplier_id',
                'po.supplier_name_snapshot',
                'po.supplier_tax_number_snapshot',
                'po.status',
                'po.currency',
                'po.total_cost',
                'po.notes',
                'po.ordered_at',
                'po.cancelled_at',
                'po.cancel_reason',
                'po.created_at',
            )
            ->selectSub(function ($query): void {
                $query->from('purchase_order_items')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('purchase_order_items.purchase_order_id', 'po.id')
                    ->whereColumn('purchase_order_items.business_id', 'po.business_id');
            }, 'line_count')
            ->selectSub(function ($query): void {
                $query->from('purchase_order_items')
                    ->selectRaw('COALESCE(SUM(quantity_ordered), 0)')
                    ->whereColumn('purchase_order_items.purchase_order_id', 'po.id')
                    ->whereColumn('purchase_order_items.business_id', 'po.business_id');
            }, 'quantity_ordered')
            ->selectSub(function ($query): void {
                $query->from('purchase_order_items')
                    ->selectRaw('COALESCE(SUM(quantity_received), 0)')
                    ->whereColumn('purchase_order_items.purchase_order_id', 'po.id')
                    ->whereColumn('purchase_order_items.business_id', 'po.business_id');
            }, 'quantity_received')
            ->orderByDesc('po.created_at')
            ->limit(250)
            ->get()
            ->map(function (object $row): object {
                $row->line_count = (int) $row->line_count;

                return $row;
            });

        return response()->json(['data' => $rows]);
    }

    public function show(string $purchaseOrder): JsonResponse
    {
        $business = app(Business::class);

        $order = DB::table('purchase_orders as po')
            ->join('locations as l', function ($join) use ($business): void {
                $join->on('l.id', '=', 'po.location_id')
                    ->where('l.business_id', $business->getKey());
            })
            ->where('po.business_id', $business->getKey())
            ->where('po.id', $purchaseOrder)
            ->first([
                'po.*',
                'l.name as location_name',
            ]);

        abort_unless($order, 404);

        $items = DB::table('purchase_order_items')
            ->where('business_id', $business->getKey())
            ->where('purchase_order_id', $purchaseOrder)
            ->orderBy('product_name_snapshot')
            ->get();

        $receipts = DB::table('goods_receipts')
            ->where('business_id', $business->getKey())
            ->where('purchase_order_id', $purchaseOrder)
            ->orderByDesc('received_at')
            ->get();

        return response()->json([
            'data' => [
                'order' => $order,
                'items' => $items,
                'receipts' => $receipts,
            ],
        ]);
    }

    public function store(CreatePurchaseOrderRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->purchasing->createPurchaseOrder(
                app(Business::class),
                $request->validated(),
                (int) $request->user()->id,
            ),
        ], 201);
    }

    public function place(Request $request, string $purchaseOrder): JsonResponse
    {
        return response()->json([
            'data' => $this->purchasing->place(
                app(Business::class),
                $purchaseOrder,
                (int) $request->user()->id,
            ),
        ]);
    }

    public function cancel(CancelPurchaseOrderRequest $request, string $purchaseOrder): JsonResponse
    {
        return response()->json([
            'data' => $this->purchasing->cancel(
                app(Business::class),
                $purchaseOrder,
                $request->validated('reason'),
                (int) $request->user()->id,
            ),
        ]);
    }

    public function receive(ReceivePurchaseOrderRequest $request, string $purchaseOrder): JsonResponse
    {
        return response()->json([
            'data' => $this->purchasing->receive(
                app(Business::class),
                $purchaseOrder,
                $request->validated(),
                (int) $request->user()->id,
            ),
        ], 201);
    }

    public function events(string $purchaseOrder): JsonResponse
    {
        $business = app(Business::class);

        abort_unless(
            DB::table('purchase_orders')
                ->where('business_id', $business->getKey())
                ->where('id', $purchaseOrder)
                ->exists(),
            404,
        );

        $rows = DB::table('purchase_order_events as poe')
            ->leftJoin('users as actor', 'actor.id', '=', 'poe.actor_user_id')
            ->where('poe.business_id', $business->getKey())
            ->where('poe.purchase_order_id', $purchaseOrder)
            ->orderByDesc('poe.occurred_at')
            ->orderByDesc('poe.id')
            ->get([
                'poe.id',
                'poe.event',
                'poe.previous_status',
                'poe.new_status',
                'poe.metadata',
                'poe.occurred_at',
                'actor.name as actor_name',
            ])
            ->map(fn (object $event): array => [
                'id' => $event->id,
                'event' => $event->event,
                'previous_status' => $event->previous_status,
                'new_status' => $event->new_status,
                'metadata' => $event->metadata
                    ? json_decode($event->metadata, true, 512, JSON_THROW_ON_ERROR)
                    : null,
                'occurred_at' => $event->occurred_at,
                'actor_name' => $event->actor_name,
            ]);

        return response()->json(['data' => $rows]);
    }

    private function activeLocationId(Request $request, Business $business): string
    {
        $locationId = $request->string('location_id')->toString();

        if ($locationId === '') {
            throw ValidationException::withMessages([
                'location_id' => 'Active location context is required.',
            ]);
        }

        $valid = DB::table('locations')
            ->where('business_id', $business->getKey())
            ->where('id', $locationId)
            ->where('is_active', true)
            ->exists();

        if (! $valid) {
            throw ValidationException::withMessages([
                'location_id' => 'The selected location is not active in this business.',
            ]);
        }

        return $locationId;
    }
}
