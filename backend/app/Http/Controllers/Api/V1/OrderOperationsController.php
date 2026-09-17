<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AddOrderItemRequest;
use App\Http\Requests\Api\V1\ApplyOrderDiscountRequest;
use App\Http\Requests\Api\V1\MoveOrderTableRequest;
use App\Http\Requests\Api\V1\OrderReasonRequest;
use App\Http\Requests\Api\V1\OverrideOrderItemPriceRequest;
use App\Http\Requests\Api\V1\UpdateOrderItemRequest;
use App\Models\Business;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Sales\OrderOperationsService;
use Illuminate\Http\JsonResponse;

class OrderOperationsController extends Controller
{
    public function __construct(private readonly OrderOperationsService $operations) {}

    public function addItem(AddOrderItemRequest $request, Order $order): JsonResponse
    {
        return $this->response($this->operations->addItem(app(Business::class), $order, $request->validated()));
    }

    public function updateItem(UpdateOrderItemRequest $request, OrderItem $item): JsonResponse
    {
        return $this->response($this->operations->updateItem(app(Business::class), $item, $request->validated()));
    }

    public function removeItem(OrderReasonRequest $request, OrderItem $item): JsonResponse
    {
        return $this->response($this->operations->removeItem(app(Business::class), $request->user(), $item, $request->validated('reason')));
    }

    public function overridePrice(OverrideOrderItemPriceRequest $request, OrderItem $item): JsonResponse
    {
        return $this->response($this->operations->overridePrice(app(Business::class), $request->user(), $item, (string) $request->validated('unit_price'), $request->validated('reason')));
    }

    public function discount(ApplyOrderDiscountRequest $request, Order $order): JsonResponse
    {
        return $this->response($this->operations->applyDiscount(app(Business::class), $request->user(), $order, (string) $request->validated('amount'), $request->validated('reason')));
    }

    public function moveTable(MoveOrderTableRequest $request, Order $order): JsonResponse
    {
        return $this->response($this->operations->moveTable(app(Business::class), $request->user(), $order, $request->validated('venue_table_id'), $request->validated('reason')));
    }

    private function response(Order $order): JsonResponse
    {
        return response()->json(['data' => $order]);
    }
}
