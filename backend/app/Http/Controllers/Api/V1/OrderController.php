<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreOrderRequest;
use App\Models\Business;
use App\Models\Order;
use App\Services\Sales\CreateOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $business = app(Business::class);

        $orders = Order::query()
            ->forBusiness($business)
            ->with('items')
            ->when($request->string('status')->isNotEmpty(), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->latest('opened_at')
            ->paginate(min((int) $request->integer('per_page', 20), 100));

        return response()->json($orders);
    }

    public function store(StoreOrderRequest $request, CreateOrder $createOrder): JsonResponse
    {
        $order = $createOrder->execute(app(Business::class), $request->user(), $request->validated());

        return response()->json(['data' => $order], 201);
    }

    public function show(string $order): JsonResponse
    {
        $record = Order::query()
            ->forBusiness(app(Business::class))
            ->with(['items', 'payments'])
            ->whereKey($order)
            ->firstOrFail();

        return response()->json(['data' => $record]);
    }
}
