<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\VenueArea;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VenueController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $business = app(Business::class);
        $locationId = $request->string('location_id')->toString();

        $areas = VenueArea::query()->forBusiness($business)
            ->when($locationId !== '', fn ($query) => $query->where('location_id', $locationId))
            ->with(['tables' => fn ($query) => $query
                ->forBusiness($business)
                ->where('location_id', $locationId)
                ->where('is_active', true)])
            ->orderBy('sort_order')->orderBy('name')->get();

        return response()->json(['data' => $areas]);
    }
}
