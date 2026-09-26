<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SaveBusinessLocationRequest;
use App\Http\Requests\Api\V1\SetBusinessLocationStatusRequest;
use App\Models\Business;
use App\Services\Tenancy\ManageBusinessLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class BusinessLocationController extends Controller
{
    public function __construct(private readonly ManageBusinessLocation $locations) {}

    public function index(): JsonResponse
    {
        $business = app(Business::class);

        $rows = DB::table('locations')
            ->where('business_id', $business->getKey())
            ->select('id', 'name', 'code', 'type', 'address', 'is_active')
            ->selectSub(function ($query): void {
                $query->from('venue_tables')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('venue_tables.location_id', 'locations.id')
                    ->whereColumn('venue_tables.business_id', 'locations.business_id');
            }, 'table_count')
            ->selectSub(function ($query): void {
                $query->from('cash_registers')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('cash_registers.location_id', 'locations.id')
                    ->whereColumn('cash_registers.business_id', 'locations.business_id');
            }, 'cash_register_count')
            ->selectSub(function ($query): void {
                $query->from('orders')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('orders.location_id', 'locations.id')
                    ->whereColumn('orders.business_id', 'locations.business_id')
                    ->whereIn('orders.status', ['open', 'payment_due']);
            }, 'open_order_count')
            ->selectSub(function ($query): void {
                $query->from('cash_sessions')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('cash_sessions.location_id', 'locations.id')
                    ->whereColumn('cash_sessions.business_id', 'locations.business_id')
                    ->where('cash_sessions.status', 'open');
            }, 'open_cash_session_count')
            ->selectSub(function ($query): void {
                $query->from('purchase_orders')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('purchase_orders.location_id', 'locations.id')
                    ->whereColumn('purchase_orders.business_id', 'locations.business_id')
                    ->whereIn('purchase_orders.status', ['draft', 'ordered', 'partially_received']);
            }, 'open_purchase_order_count')
            ->selectSub(function ($query): void {
                $query->from('inventory_counts')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('inventory_counts.location_id', 'locations.id')
                    ->whereColumn('inventory_counts.business_id', 'locations.business_id')
                    ->where('inventory_counts.status', 'draft');
            }, 'open_inventory_count_count')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        $activeCount = $rows->filter(fn (object $row): bool => (bool) $row->is_active)->count();

        $rows->transform(function (object $row) use ($activeCount): object {
            $row->is_active = (bool) $row->is_active;
            $row->table_count = (int) $row->table_count;
            $row->cash_register_count = (int) $row->cash_register_count;
            $row->open_order_count = (int) $row->open_order_count;
            $row->open_cash_session_count = (int) $row->open_cash_session_count;
            $row->open_purchase_order_count = (int) $row->open_purchase_order_count;
            $row->open_inventory_count_count = (int) $row->open_inventory_count_count;
            $row->is_last_active = $row->is_active && $activeCount === 1;

            return $row;
        });

        return response()->json(['data' => $rows]);
    }

    public function events(string $location): JsonResponse
    {
        $business = app(Business::class);

        abort_unless(
            DB::table('locations')
                ->where('business_id', $business->getKey())
                ->where('id', $location)
                ->exists(),
            404,
        );

        $rows = DB::table('business_location_audits as bla')
            ->join('users as actor', 'actor.id', '=', 'bla.performed_by_user_id')
            ->where('bla.business_id', $business->getKey())
            ->where('bla.location_id', $location)
            ->orderByDesc('bla.performed_at')
            ->orderByDesc('bla.id')
            ->get([
                'bla.id',
                'bla.action',
                'bla.previous_state',
                'bla.new_state',
                'bla.performed_at',
                'actor.name as performed_by_name',
            ])
            ->map(fn (object $event): array => [
                'id' => $event->id,
                'action' => $event->action,
                'previous_state' => $event->previous_state ? json_decode($event->previous_state, true, 512, JSON_THROW_ON_ERROR) : null,
                'new_state' => $event->new_state ? json_decode($event->new_state, true, 512, JSON_THROW_ON_ERROR) : null,
                'performed_at' => $event->performed_at,
                'performed_by_name' => $event->performed_by_name,
            ]);

        return response()->json(['data' => $rows]);
    }

    public function store(SaveBusinessLocationRequest $request): JsonResponse
    {
        $business = app(Business::class);
        $data = $request->validated();

        $location = ! empty($data['id'])
            ? $this->locations->update($business, $data['id'], $data, (int) $request->user()->id)
            : $this->locations->create($business, $data, (int) $request->user()->id);

        return response()->json(
            ['data' => $location],
            ! empty($data['id']) ? 200 : 201,
        );
    }

    public function setStatus(SetBusinessLocationStatusRequest $request, string $location): JsonResponse
    {
        return response()->json([
            'data' => $this->locations->setStatus(
                app(Business::class),
                $location,
                (bool) $request->validated('is_active'),
                (int) $request->user()->id,
            ),
        ]);
    }
}
