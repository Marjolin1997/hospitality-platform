<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\VenueArea;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VenueController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $business = app(Business::class);
        $locationId = $request->string('location_id')->toString();

        if ($locationId === '' || ! DB::table('locations')
            ->where('business_id', $business->getKey())
            ->where('id', $locationId)
            ->where('is_active', true)
            ->exists()) {
            throw ValidationException::withMessages([
                'location_id' => 'An active location context is required.',
            ]);
        }

        $areas = VenueArea::query()->forBusiness($business)
            ->where('location_id', $locationId)
            ->where('is_active', true)
            ->with(['tables' => fn ($query) => $query
                ->forBusiness($business)
                ->where('location_id', $locationId)
                ->where('is_active', true)])
            ->orderBy('sort_order')->orderBy('name')->get();

        return response()->json(['data' => $areas]);
    }
}
