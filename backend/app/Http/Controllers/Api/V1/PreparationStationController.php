<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SavePreparationStationRequest;
use App\Http\Requests\Api\V1\SetPreparationStationStatusRequest;
use App\Models\Business;
use App\Services\Operations\ManagePreparationStations;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class PreparationStationController extends Controller
{
    public function __construct(private readonly ManagePreparationStations $stations) {}

    public function index(): JsonResponse
    {
        $business = app(Business::class);

        $rows = DB::table('preparation_stations as ps')
            ->where('ps.business_id', $business->getKey())
            ->select('ps.id', 'ps.name', 'ps.code', 'ps.sort_order', 'ps.is_active')
            ->selectSub(function ($query): void {
                $query->from('products')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('products.business_id', 'ps.business_id')
                    ->whereColumn('products.preparation_station', 'ps.code');
            }, 'product_count')
            ->selectSub(function ($query): void {
                $query->from('products')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('products.business_id', 'ps.business_id')
                    ->whereColumn('products.preparation_station', 'ps.code')
                    ->where('products.is_active', true);
            }, 'active_product_count')
            ->selectSub(function ($query): void {
                $query->from('order_items')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('order_items.business_id', 'ps.business_id')
                    ->whereColumn('order_items.preparation_station', 'ps.code')
                    ->whereIn('order_items.preparation_status', ['pending', 'sent', 'preparing', 'ready']);
            }, 'open_ticket_count')
            ->orderByDesc('ps.is_active')
            ->orderBy('ps.sort_order')
            ->orderBy('ps.name')
            ->get()
            ->map(function (object $row): object {
                $row->sort_order = (int) $row->sort_order;
                $row->is_active = (bool) $row->is_active;
                $row->product_count = (int) $row->product_count;
                $row->active_product_count = (int) $row->active_product_count;
                $row->open_ticket_count = (int) $row->open_ticket_count;
                return $row;
            });

        return response()->json(['data' => $rows]);
    }

    public function store(SavePreparationStationRequest $request): JsonResponse
    {
        $station = $this->stations->save(
            app(Business::class),
            $request->validated(),
            (int) $request->user()->id,
        );

        return response()->json(
            ['data' => $station],
            $request->filled('id') ? 200 : 201,
        );
    }

    public function setStatus(SetPreparationStationStatusRequest $request, string $station): JsonResponse
    {
        return response()->json([
            'data' => $this->stations->setStatus(
                app(Business::class),
                $station,
                (bool) $request->validated('is_active'),
                (int) $request->user()->id,
            ),
        ]);
    }

    public function events(string $station): JsonResponse
    {
        $business = app(Business::class);

        abort_unless(
            DB::table('preparation_stations')
                ->where('business_id', $business->getKey())
                ->where('id', $station)
                ->exists(),
            404,
        );

        $rows = DB::table('business_configuration_audits as bca')
            ->join('users as actor', 'actor.id', '=', 'bca.performed_by_user_id')
            ->where('bca.business_id', $business->getKey())
            ->where('bca.entity_type', 'preparation_station')
            ->where('bca.entity_id', $station)
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
                'previous_state' => $event->previous_state ? json_decode($event->previous_state, true, 512, JSON_THROW_ON_ERROR) : null,
                'new_state' => $event->new_state ? json_decode($event->new_state, true, 512, JSON_THROW_ON_ERROR) : null,
                'performed_at' => $event->performed_at,
                'performed_by_name' => $event->performed_by_name,
            ]);

        return response()->json(['data' => $rows]);
    }
}
