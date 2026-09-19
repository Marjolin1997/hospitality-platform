<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SaveCashRegisterRequest;
use App\Http\Requests\Api\V1\SaveVenueAreaRequest;
use App\Http\Requests\Api\V1\SaveVenueTableRequest;
use App\Http\Requests\Api\V1\SetCashRegisterStatusRequest;
use App\Http\Requests\Api\V1\SetVenueAreaStatusRequest;
use App\Http\Requests\Api\V1\SetVenueTableStatusRequest;
use App\Models\Business;
use App\Services\Operations\ManageVenueConfiguration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class VenueManagementController extends Controller
{
    public function __construct(private readonly ManageVenueConfiguration $venue) {}

    public function index(Request $request): JsonResponse
    {
        $business = app(Business::class);
        $locationId = $this->locationId($request, $business);

        $areas = DB::table('venue_areas')
            ->where('business_id', $business->getKey())
            ->where('location_id', $locationId)
            ->select('id', 'location_id', 'name', 'sort_order', 'is_active')
            ->selectSub(function ($query): void {
                $query->from('venue_tables')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('venue_tables.venue_area_id', 'venue_areas.id')
                    ->whereColumn('venue_tables.business_id', 'venue_areas.business_id');
            }, 'table_count')
            ->selectSub(function ($query): void {
                $query->from('venue_tables')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('venue_tables.venue_area_id', 'venue_areas.id')
                    ->whereColumn('venue_tables.business_id', 'venue_areas.business_id')
                    ->where('venue_tables.is_active', true);
            }, 'active_table_count')
            ->orderByDesc('is_active')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(function (object $area): object {
                $area->is_active = (bool) $area->is_active;
                $area->sort_order = (int) $area->sort_order;
                $area->table_count = (int) $area->table_count;
                $area->active_table_count = (int) $area->active_table_count;

                return $area;
            });

        $tables = DB::table('venue_tables as vt')
            ->leftJoin('venue_areas as va', function ($join) use ($business): void {
                $join->on('va.id', '=', 'vt.venue_area_id')
                    ->where('va.business_id', $business->getKey());
            })
            ->where('vt.business_id', $business->getKey())
            ->where('vt.location_id', $locationId)
            ->select(
                'vt.id',
                'vt.location_id',
                'vt.venue_area_id',
                'vt.name',
                'vt.capacity',
                'vt.is_active',
                'va.name as area_name',
                'va.is_active as area_is_active',
            )
            ->selectSub(function ($query): void {
                $query->from('orders')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('orders.venue_table_id', 'vt.id')
                    ->whereColumn('orders.business_id', 'vt.business_id')
                    ->whereIn('orders.status', ['open', 'payment_due']);
            }, 'open_order_count')
            ->orderByDesc('vt.is_active')
            ->orderBy('va.sort_order')
            ->orderBy('vt.name')
            ->get()
            ->map(function (object $table): object {
                $table->is_active = (bool) $table->is_active;
                $table->area_is_active = $table->area_is_active === null ? null : (bool) $table->area_is_active;
                $table->capacity = (int) $table->capacity;
                $table->open_order_count = (int) $table->open_order_count;

                return $table;
            });

        return response()->json([
            'data' => [
                'areas' => $areas,
                'tables' => $tables,
            ],
        ]);
    }

    public function saveArea(SaveVenueAreaRequest $request): JsonResponse
    {
        $area = $this->venue->saveArea(
            app(Business::class),
            $request->validated(),
            (int) $request->user()->id,
        );

        return response()->json(
            ['data' => $area],
            $request->filled('id') ? 200 : 201,
        );
    }

    public function setAreaStatus(SetVenueAreaStatusRequest $request, string $area): JsonResponse
    {
        return response()->json([
            'data' => $this->venue->setAreaStatus(
                app(Business::class),
                $area,
                (bool) $request->validated('is_active'),
                (int) $request->user()->id,
            ),
        ]);
    }

    public function saveTable(SaveVenueTableRequest $request): JsonResponse
    {
        $table = $this->venue->saveTable(
            app(Business::class),
            $request->validated(),
            (int) $request->user()->id,
        );

        return response()->json(
            ['data' => $table],
            $request->filled('id') ? 200 : 201,
        );
    }

    public function setTableStatus(SetVenueTableStatusRequest $request, string $table): JsonResponse
    {
        return response()->json([
            'data' => $this->venue->setTableStatus(
                app(Business::class),
                $table,
                (bool) $request->validated('is_active'),
                (int) $request->user()->id,
            ),
        ]);
    }

    public function registers(Request $request): JsonResponse
    {
        $business = app(Business::class);
        $locationId = $this->locationId($request, $business);

        $rows = DB::table('cash_registers as cr')
            ->where('cr.business_id', $business->getKey())
            ->where('cr.location_id', $locationId)
            ->select(
                'cr.id',
                'cr.location_id',
                'cr.name',
                'cr.code',
                'cr.is_active',
                'cr.fiscal_tcr_code',
            )
            ->selectSub(function ($query): void {
                $query->from('cash_sessions')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('cash_sessions.cash_register_id', 'cr.id')
                    ->whereColumn('cash_sessions.business_id', 'cr.business_id')
                    ->where('cash_sessions.status', 'open');
            }, 'open_session_count')
            ->orderByDesc('cr.is_active')
            ->orderBy('cr.name')
            ->get()
            ->map(function (object $register): object {
                $register->is_active = (bool) $register->is_active;
                $register->open_session_count = (int) $register->open_session_count;

                return $register;
            });

        return response()->json(['data' => $rows]);
    }

    public function saveRegister(SaveCashRegisterRequest $request): JsonResponse
    {
        $register = $this->venue->saveRegister(
            app(Business::class),
            $request->validated(),
            (int) $request->user()->id,
        );

        return response()->json(
            ['data' => $register],
            $request->filled('id') ? 200 : 201,
        );
    }

    public function setRegisterStatus(SetCashRegisterStatusRequest $request, string $register): JsonResponse
    {
        return response()->json([
            'data' => $this->venue->setRegisterStatus(
                app(Business::class),
                $register,
                (bool) $request->validated('is_active'),
                (int) $request->user()->id,
            ),
        ]);
    }

    private function locationId(Request $request, Business $business): string
    {
        $locationId = $request->string('location_id')->toString();

        if ($locationId === '') {
            throw ValidationException::withMessages([
                'location_id' => 'Location context is required.',
            ]);
        }

        $exists = DB::table('locations')
            ->where('business_id', $business->getKey())
            ->where('id', $locationId)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'location_id' => 'The selected location does not belong to this business.',
            ]);
        }

        return $locationId;
    }
}
