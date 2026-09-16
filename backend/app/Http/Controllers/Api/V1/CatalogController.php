<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\ProductCategory;
use Illuminate\Http\JsonResponse;

class CatalogController extends Controller
{
    public function index(): JsonResponse
    {
        $business = app(Business::class);

        $categories = ProductCategory::query()
            ->forBusiness($business)
            ->where('is_active', true)
            ->with(['products' => fn ($query) => $query
                ->where('is_active', true)
                ->orderBy('name')])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $categories]);
    }
}
