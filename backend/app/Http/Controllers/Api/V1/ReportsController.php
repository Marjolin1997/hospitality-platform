<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReportRangeRequest;
use App\Models\Business;
use App\Services\Reports\ReportingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class ReportsController extends Controller
{
    public function __construct(private readonly ReportingService $reports) {}

    public function locations(): JsonResponse
    {
        $business = app(Business::class);

        $rows = DB::table('locations')
            ->where('business_id', $business->getKey())
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'is_active'])
            ->map(function (object $row): object {
                $row->is_active = (bool) $row->is_active;

                return $row;
            });

        return response()->json(['data' => $rows]);
    }

    public function operational(ReportRangeRequest $request): JsonResponse
    {
        $data = $request->validated();

        return response()->json([
            'data' => $this->reports->operational(
                app(Business::class),
                $data['location_id'],
                $data['from'],
                $data['to'],
            ),
        ]);
    }

    public function financial(ReportRangeRequest $request): JsonResponse
    {
        $data = $request->validated();

        return response()->json([
            'data' => $this->reports->financial(
                app(Business::class),
                $data['location_id'],
                $data['from'],
                $data['to'],
            ),
        ]);
    }
}
