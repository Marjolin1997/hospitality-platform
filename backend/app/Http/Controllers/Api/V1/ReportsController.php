<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReportRangeRequest;
use App\Models\Business;
use App\Services\Reports\ReportingService;
use Illuminate\Http\JsonResponse;

final class ReportsController extends Controller
{
    public function __construct(private readonly ReportingService $reports) {}

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
