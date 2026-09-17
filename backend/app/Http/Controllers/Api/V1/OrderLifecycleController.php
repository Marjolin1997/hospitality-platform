<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CancelOrderItemRequest;
use App\Http\Requests\Api\V1\CancelOrderRequest;
use App\Http\Requests\Api\V1\TransitionOrderItemRequest;
use App\Models\Business;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Sales\OrderLifecycleService;
use Illuminate\Http\JsonResponse;

class OrderLifecycleController extends Controller
{
    public function __construct(private readonly OrderLifecycleService $lifecycle) {}

    public function transition(TransitionOrderItemRequest $request, OrderItem $item): JsonResponse
    {
        $item = $this->lifecycle->transitionPreparation(
            app(Business::class),
            $item,
            $request->validated('status'),
        );

        return response()->json(['data' => $item]);
    }

    public function cancelItem(CancelOrderItemRequest $request, OrderItem $item): JsonResponse
    {
        $item = $this->lifecycle->voidItem(
            app(Business::class),
            $request->user(),
            $item,
            $request->validated('reason'),
        );

        return response()->json(['data' => $item]);
    }

    public function cancelOrder(CancelOrderRequest $request, Order $order): JsonResponse
    {
        $order = $this->lifecycle->cancelOrder(
            app(Business::class),
            $request->user(),
            $order,
            $request->validated('reason'),
        );

        return response()->json(['data' => $order]);
    }
}
