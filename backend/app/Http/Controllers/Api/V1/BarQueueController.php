<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\OrderItem;
use App\Services\Sales\OrderLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BarQueueController extends Controller
{
    public function __construct(private readonly OrderLifecycleService $lifecycle) {}

    public function index(Request $request): JsonResponse
    {
        $locationId = $request->string('location_id')->toString();
        $station = $request->string('station')->toString();

        $items = OrderItem::query()
            ->forBusiness(app(Business::class))
            ->whereIn('preparation_status', ['sent', 'preparing', 'ready'])
            ->when($locationId !== '', fn ($query) => $query->whereHas(
                'order', fn ($order) => $order->where('location_id', $locationId)
            ))
            ->when($station !== '', fn ($query) => $query->where('preparation_station', $station))
            ->with('order')
            ->orderByRaw("FIELD(preparation_status, 'preparing', 'sent', 'ready')")
            ->orderBy('sent_at')
            ->get();

        return response()->json(['data' => $items]);
    }

    public function transition(Request $request, string $item): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['preparing', 'ready', 'served'])],
            'location_id' => ['required', 'string'],
        ]);

        $record = OrderItem::query()
            ->forBusiness(app(Business::class))
            ->whereKey($item)
            ->whereHas('order', fn ($query) => $query->where('location_id', $validated['location_id']))
            ->firstOrFail();

        $record = $this->lifecycle->transitionPreparation(
            app(Business::class),
            $record,
            $validated['status'],
        );

        return response()->json(['data' => $record]);
    }
}
