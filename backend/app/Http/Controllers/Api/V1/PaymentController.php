<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CollectPaymentRequest;
use App\Http\Requests\Api\V1\OpenCashSessionRequest;
use App\Models\Business;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Order;
use App\Services\Payments\CollectPayment;
use App\Services\Payments\OpenCashSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function registers(): JsonResponse
    {
        return response()->json(['data' => CashRegister::query()->forBusiness(app(Business::class))
            ->where('is_active', true)->with('location')->orderBy('name')->get()]);
    }

    public function currentSession(Request $request): JsonResponse
    {
        $session = CashSession::query()->forBusiness(app(Business::class))
            ->where('status', 'open')->with('register')->latest('opened_at')->first();
        return response()->json(['data' => $session]);
    }

    public function openSession(OpenCashSessionRequest $request, OpenCashSession $service): JsonResponse
    {
        $session = $service->execute(app(Business::class), $request->user(), $request->validated());
        return response()->json(['data' => $session->load('register')], 201);
    }

    public function collect(CollectPaymentRequest $request, string $order, CollectPayment $service): JsonResponse
    {
        $record = Order::query()->forBusiness(app(Business::class))->whereKey($order)->firstOrFail();
        $payment = $service->execute(app(Business::class), $request->user(), $record, $request->validated());
        return response()->json(['data' => $payment], 201);
    }
}
