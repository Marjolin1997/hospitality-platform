<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AddOrderItemRequest;
use App\Http\Requests\Api\V1\ApplyOrderDiscountRequest;
use App\Http\Requests\Api\V1\MergeOrderRequest;
use App\Http\Requests\Api\V1\MoveOrderTableRequest;
use App\Http\Requests\Api\V1\OrderReasonRequest;
use App\Http\Requests\Api\V1\OverrideOrderItemPriceRequest;
use App\Http\Requests\Api\V1\SplitOrderRequest;
use App\Http\Requests\Api\V1\UpdateOrderItemRequest;
use App\Models\Business;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Sales\OrderOperationsService;
use App\Services\Sales\OrderTransferService;
use Illuminate\Http\JsonResponse;

class OrderOperationsController extends Controller
{
    public function __construct(private readonly OrderOperationsService $operations, private readonly OrderTransferService $transfers) {}
    public function addItem(AddOrderItemRequest $r,Order $o):JsonResponse{return $this->response($this->operations->addItem(app(Business::class),$o,$r->validated()));}
    public function updateItem(UpdateOrderItemRequest $r,OrderItem $i):JsonResponse{return $this->response($this->operations->updateItem(app(Business::class),$i,$r->validated()));}
    public function removeItem(OrderReasonRequest $r,OrderItem $i):JsonResponse{return $this->response($this->operations->removeItem(app(Business::class),$r->user(),$i,$r->validated('reason')));}
    public function overridePrice(OverrideOrderItemPriceRequest $r,OrderItem $i):JsonResponse{return $this->response($this->operations->overridePrice(app(Business::class),$r->user(),$i,(string)$r->validated('unit_price'),$r->validated('reason')));}
    public function discount(ApplyOrderDiscountRequest $r,Order $o):JsonResponse{return $this->response($this->operations->applyDiscount(app(Business::class),$r->user(),$o,(string)$r->validated('amount'),$r->validated('reason')));}
    public function moveTable(MoveOrderTableRequest $r,Order $o):JsonResponse{return $this->response($this->operations->moveTable(app(Business::class),$r->user(),$o,$r->validated('venue_table_id'),$r->validated('reason')));}
    public function split(SplitOrderRequest $r,Order $o):JsonResponse{return response()->json(['data'=>$this->transfers->split(app(Business::class),$r->user(),$o,$r->validated())],201);}
    public function merge(MergeOrderRequest $r,Order $o):JsonResponse{return $this->response($this->transfers->merge(app(Business::class),$r->user(),$o,$r->validated()));}
    private function response(Order $o):JsonResponse{return response()->json(['data'=>$o]);}
}
