<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $businesses = $request->user()->businesses()
            ->wherePivot('status', 'active')->where('businesses.status', 'active')
            ->with(['locations' => fn ($query) => $query->where('is_active', true)->orderBy('name')])
            ->orderBy('businesses.name')->get();

        return response()->json(['data' => $businesses->map(fn ($business) => [
            'id' => $business->getKey(), 'name' => $business->name, 'currency' => $business->currency,
            'timezone' => $business->timezone, 'role_id' => $business->pivot->role_id,
            'locations' => $business->locations->map(fn ($location) => ['id' => $location->getKey(), 'name' => $location->name]),
        ])->values()]);
    }
}
