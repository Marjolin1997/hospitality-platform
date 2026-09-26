<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CancelInventoryCountRequest;
use App\Http\Requests\Api\V1\CreateInventoryCountRequest;
use App\Http\Requests\Api\V1\CreateInventoryTransferRequest;
use App\Http\Requests\Api\V1\SetInventoryReorderLevelRequest;
use App\Http\Requests\Api\V1\UpdateInventoryCountRequest;
use App\Models\Business;
use App\Services\Inventory\ManageInventoryControl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InventoryControlController extends Controller
{
    public function __construct(private readonly ManageInventoryControl $inventory) {}

    public function setReorderLevel(SetInventoryReorderLevelRequest $request, string $product): JsonResponse
    {
        return response()->json([
            'data' => $this->inventory->setReorderLevel(
                app(Business::class),
                (string) $request->validated('location_id'),
                $product,
                $request->validated('reorder_level'),
                (int) $request->user()->id,
            ),
        ]);
    }

    public function reorderLevelEvents(Request $request, string $product): JsonResponse
    {
        $business = app(Business::class);
        $locationId = $this->activeLocationId($request, $business);

        $validProduct = DB::table('products')
            ->where('business_id', $business->getKey())
            ->where('id', $product)
            ->where('tracks_stock', true)
            ->exists();

        abort_unless($validProduct, 404);

        $stockId = DB::table('inventory_stocks')
            ->where('business_id', $business->getKey())
            ->where('location_id', $locationId)
            ->where('product_id', $product)
            ->value('id');

        if (! $stockId) {
            return response()->json(['data' => []]);
        }

        $rows = DB::table('business_configuration_audits as bca')
            ->join('users as actor', 'actor.id', '=', 'bca.performed_by_user_id')
            ->where('bca.business_id', $business->getKey())
            ->where('bca.location_id', $locationId)
            ->where('bca.entity_type', 'inventory_stock')
            ->where('bca.entity_id', $stockId)
            ->where('bca.action', 'reorder_level_changed')
            ->orderByDesc('bca.performed_at')
            ->orderByDesc('bca.id')
            ->get([
                'bca.id',
                'bca.action',
                'bca.previous_state',
                'bca.new_state',
                'bca.performed_at',
                'actor.name as performed_by_name',
            ])
            ->map(fn (object $event): array => [
                'id' => $event->id,
                'action' => $event->action,
                'previous_state' => $event->previous_state
                    ? json_decode($event->previous_state, true, 512, JSON_THROW_ON_ERROR)
                    : null,
                'new_state' => $event->new_state
                    ? json_decode($event->new_state, true, 512, JSON_THROW_ON_ERROR)
                    : null,
                'performed_at' => $event->performed_at,
                'performed_by_name' => $event->performed_by_name,
            ]);

        return response()->json(['data' => $rows]);
    }

    public function movements(Request $request): JsonResponse
    {
        $business = app(Business::class);
        $locationId = $this->activeLocationId($request, $business);

        $rows = DB::table('inventory_movements as im')
            ->join('products as p', function ($join) use ($business): void {
                $join->on('p.id', '=', 'im.product_id')
                    ->where('p.business_id', $business->getKey());
            })
            ->join('users as u', 'u.id', '=', 'im.created_by_user_id')
            ->where('im.business_id', $business->getKey())
            ->where('im.location_id', $locationId)
            ->orderByDesc('im.occurred_at')
            ->orderByDesc('im.id')
            ->limit(500)
            ->get([
                'im.id',
                'im.product_id',
                'p.name as product_name',
                'p.sku',
                'im.type',
                'im.quantity_delta',
                'im.reference_type',
                'im.reference_id',
                'im.note',
                'im.occurred_at',
                'u.id as created_by_user_id',
                'u.name as created_by_name',
            ]);

        return response()->json(['data' => $rows]);
    }

    public function transferOptions(Request $request): JsonResponse
    {
        $business = app(Business::class);
        $sourceLocationId = $this->activeLocationId($request, $business);

        $destinations = DB::table('locations')
            ->where('business_id', $business->getKey())
            ->where('is_active', true)
            ->where('id', '!=', $sourceLocationId)
            ->orderBy('name')
            ->get(['id', 'name']);

        $products = DB::table('products as p')
            ->leftJoin('inventory_stocks as s', function ($join) use ($business, $sourceLocationId): void {
                $join->on('s.product_id', '=', 'p.id')
                    ->where('s.business_id', $business->getKey())
                    ->where('s.location_id', $sourceLocationId);
            })
            ->where('p.business_id', $business->getKey())
            ->where('p.tracks_stock', true)
            ->select(
                'p.id',
                'p.name',
                'p.sku',
                'p.unit_label',
                DB::raw('COALESCE(s.quantity_on_hand, 0) as quantity_on_hand'),
            )
            ->orderBy('p.name')
            ->get();

        return response()->json([
            'data' => [
                'source_location_id' => $sourceLocationId,
                'destinations' => $destinations,
                'products' => $products,
            ],
        ]);
    }

    public function transfers(Request $request): JsonResponse
    {
        $business = app(Business::class);
        $locationId = $this->activeLocationId($request, $business);

        $rows = DB::table('inventory_transfers as it')
            ->join('locations as source', function ($join) use ($business): void {
                $join->on('source.id', '=', 'it.source_location_id')
                    ->where('source.business_id', $business->getKey());
            })
            ->join('locations as destination', function ($join) use ($business): void {
                $join->on('destination.id', '=', 'it.destination_location_id')
                    ->where('destination.business_id', $business->getKey());
            })
            ->join('users as actor', 'actor.id', '=', 'it.created_by_user_id')
            ->where('it.business_id', $business->getKey())
            ->where(function ($query) use ($locationId): void {
                $query->where('it.source_location_id', $locationId)
                    ->orWhere('it.destination_location_id', $locationId);
            })
            ->select(
                'it.id',
                'it.number',
                'it.source_location_id',
                'source.name as source_location_name',
                'it.destination_location_id',
                'destination.name as destination_location_name',
                'it.status',
                'it.note',
                'it.posted_at',
                'actor.name as created_by_name',
            )
            ->selectSub(function ($query): void {
                $query->from('inventory_transfer_items')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('inventory_transfer_items.inventory_transfer_id', 'it.id')
                    ->whereColumn('inventory_transfer_items.business_id', 'it.business_id');
            }, 'line_count')
            ->selectSub(function ($query): void {
                $query->from('inventory_transfer_items')
                    ->selectRaw('COALESCE(SUM(quantity), 0)')
                    ->whereColumn('inventory_transfer_items.inventory_transfer_id', 'it.id')
                    ->whereColumn('inventory_transfer_items.business_id', 'it.business_id');
            }, 'total_quantity')
            ->orderByDesc('it.posted_at')
            ->limit(250)
            ->get()
            ->map(function (object $row): object {
                $row->line_count = (int) $row->line_count;
                return $row;
            });

        return response()->json(['data' => $rows]);
    }

    public function showTransfer(string $transfer): JsonResponse
    {
        $business = app(Business::class);

        $record = DB::table('inventory_transfers as it')
            ->join('locations as source', function ($join) use ($business): void {
                $join->on('source.id', '=', 'it.source_location_id')
                    ->where('source.business_id', $business->getKey());
            })
            ->join('locations as destination', function ($join) use ($business): void {
                $join->on('destination.id', '=', 'it.destination_location_id')
                    ->where('destination.business_id', $business->getKey());
            })
            ->join('users as actor', 'actor.id', '=', 'it.created_by_user_id')
            ->where('it.business_id', $business->getKey())
            ->where('it.id', $transfer)
            ->first([
                'it.*',
                'source.name as source_location_name',
                'destination.name as destination_location_name',
                'actor.name as created_by_name',
            ]);

        abort_unless($record, 404);

        $items = DB::table('inventory_transfer_items')
            ->where('business_id', $business->getKey())
            ->where('inventory_transfer_id', $transfer)
            ->orderBy('product_name_snapshot')
            ->get();

        return response()->json(['data' => ['transfer' => $record, 'items' => $items]]);
    }

    public function createTransfer(CreateInventoryTransferRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->inventory->transfer(
                app(Business::class),
                $request->validated(),
                (int) $request->user()->id,
            ),
        ], 201);
    }

    public function counts(Request $request): JsonResponse
    {
        $business = app(Business::class);
        $locationId = $this->activeLocationId($request, $business);

        $rows = DB::table('inventory_counts as ic')
            ->join('users as creator', 'creator.id', '=', 'ic.created_by_user_id')
            ->leftJoin('users as poster', 'poster.id', '=', 'ic.posted_by_user_id')
            ->where('ic.business_id', $business->getKey())
            ->where('ic.location_id', $locationId)
            ->select(
                'ic.id',
                'ic.number',
                'ic.status',
                'ic.note',
                'ic.started_at',
                'ic.posted_at',
                'ic.cancelled_at',
                'ic.cancel_reason',
                'creator.name as created_by_name',
                'poster.name as posted_by_name',
            )
            ->selectSub(function ($query): void {
                $query->from('inventory_count_items')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('inventory_count_items.inventory_count_id', 'ic.id')
                    ->whereColumn('inventory_count_items.business_id', 'ic.business_id');
            }, 'line_count')
            ->selectSub(function ($query): void {
                $query->from('inventory_count_items')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('inventory_count_items.inventory_count_id', 'ic.id')
                    ->whereColumn('inventory_count_items.business_id', 'ic.business_id')
                    ->whereNotNull('inventory_count_items.counted_quantity');
            }, 'counted_line_count')
            ->selectSub(function ($query): void {
                $query->from('inventory_count_items')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('inventory_count_items.inventory_count_id', 'ic.id')
                    ->whereColumn('inventory_count_items.business_id', 'ic.business_id')
                    ->where('inventory_count_items.variance_quantity', '!=', 0);
            }, 'variance_line_count')
            ->orderByDesc('ic.started_at')
            ->limit(250)
            ->get()
            ->map(function (object $row): object {
                $row->line_count = (int) $row->line_count;
                $row->counted_line_count = (int) $row->counted_line_count;
                $row->variance_line_count = (int) $row->variance_line_count;
                return $row;
            });

        return response()->json(['data' => $rows]);
    }

    public function showCount(string $count): JsonResponse
    {
        $business = app(Business::class);

        $record = DB::table('inventory_counts as ic')
            ->join('locations as l', function ($join) use ($business): void {
                $join->on('l.id', '=', 'ic.location_id')
                    ->where('l.business_id', $business->getKey());
            })
            ->join('users as creator', 'creator.id', '=', 'ic.created_by_user_id')
            ->leftJoin('users as poster', 'poster.id', '=', 'ic.posted_by_user_id')
            ->leftJoin('users as canceller', 'canceller.id', '=', 'ic.cancelled_by_user_id')
            ->where('ic.business_id', $business->getKey())
            ->where('ic.id', $count)
            ->first([
                'ic.*',
                'l.name as location_name',
                'creator.name as created_by_name',
                'poster.name as posted_by_name',
                'canceller.name as cancelled_by_name',
            ]);

        abort_unless($record, 404);

        $items = DB::table('inventory_count_items')
            ->where('business_id', $business->getKey())
            ->where('inventory_count_id', $count)
            ->orderBy('product_name_snapshot')
            ->get();

        return response()->json(['data' => ['count' => $record, 'items' => $items]]);
    }

    public function createCount(CreateInventoryCountRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->inventory->createCount(
                app(Business::class),
                $request->validated(),
                (int) $request->user()->id,
            ),
        ], 201);
    }

    public function updateCount(UpdateInventoryCountRequest $request, string $count): JsonResponse
    {
        return response()->json([
            'data' => $this->inventory->updateCount(
                app(Business::class),
                $count,
                $request->validated(),
                (int) $request->user()->id,
            ),
        ]);
    }

    public function postCount(Request $request, string $count): JsonResponse
    {
        return response()->json([
            'data' => $this->inventory->postCount(
                app(Business::class),
                $count,
                (int) $request->user()->id,
            ),
        ]);
    }

    public function cancelCount(CancelInventoryCountRequest $request, string $count): JsonResponse
    {
        return response()->json([
            'data' => $this->inventory->cancelCount(
                app(Business::class),
                $count,
                $request->validated('reason'),
                (int) $request->user()->id,
            ),
        ]);
    }

    public function countEvents(string $count): JsonResponse
    {
        $business = app(Business::class);

        abort_unless(
            DB::table('inventory_counts')
                ->where('business_id', $business->getKey())
                ->where('id', $count)
                ->exists(),
            404,
        );

        $rows = DB::table('inventory_count_events as ice')
            ->leftJoin('users as actor', 'actor.id', '=', 'ice.actor_user_id')
            ->where('ice.business_id', $business->getKey())
            ->where('ice.inventory_count_id', $count)
            ->orderByDesc('ice.occurred_at')
            ->orderByDesc('ice.id')
            ->get([
                'ice.id',
                'ice.event',
                'ice.previous_status',
                'ice.new_status',
                'ice.metadata',
                'ice.occurred_at',
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
