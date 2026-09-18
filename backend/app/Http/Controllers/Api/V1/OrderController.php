<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SendOrderToStationRequest;
use App\Http\Requests\Api\V1\StoreOrderRequest;
use App\Models\Business;
use App\Models\Order;
use App\Services\Sales\CreateOrder;
use App\Services\Sales\SendOrderToStation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    private const RELATIONS=['items','payments.refunds','invoice.creditNote'];
    public function index(Request $request):JsonResponse{$orders=Order::query()->forBusiness(app(Business::class))->with(self::RELATIONS)->when($request->string('status')->isNotEmpty(),fn($q)=>$q->where('status',$request->string('status')->toString()))->latest('opened_at')->paginate(min((int)$request->integer('per_page',20),100));return response()->json($orders);}
    public function store(StoreOrderRequest $request,CreateOrder $service):JsonResponse{return response()->json(['data'=>$service->execute(app(Business::class),$request->user(),$request->validated())],201);}
    public function show(string $order):JsonResponse{return response()->json(['data'=>Order::query()->forBusiness(app(Business::class))->with(self::RELATIONS)->whereKey($order)->firstOrFail()]);}
    public function send(SendOrderToStationRequest $request,string $order,SendOrderToStation $service):JsonResponse{$record=Order::query()->forBusiness(app(Business::class))->whereKey($order)->firstOrFail();return response()->json(['data'=>$service->execute(app(Business::class),$record)]);}
}
