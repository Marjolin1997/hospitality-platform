<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CloseCashSessionRequest;
use App\Http\Requests\Api\V1\CollectPaymentRequest;
use App\Http\Requests\Api\V1\OpenCashSessionRequest;
use App\Http\Requests\Api\V1\RefundPaymentRequest;
use App\Http\Requests\Api\V1\StoreCashMovementRequest;
use App\Models\Business;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payments\CashSessionReconciler;
use App\Services\Payments\CloseCashSession;
use App\Services\Payments\CollectPayment;
use App\Services\Payments\OpenCashSession;
use App\Services\Payments\RecordCashMovement;
use App\Services\Payments\RefundPayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function registers(): JsonResponse { return response()->json(['data' => CashRegister::query()->forBusiness(app(Business::class))->where('is_active', true)->with('location')->orderBy('name')->get()]); }

    public function currentSession(Request $request, CashSessionReconciler $reconciler): JsonResponse
    {
        $session = CashSession::query()->forBusiness(app(Business::class))->where('status', 'open')->with('register')->latest('opened_at')->first();
        return response()->json(['data' => $session ? [...$session->toArray(), 'expected_cash_live' => $reconciler->expectedCash($session)] : null]);
    }

    public function openSession(OpenCashSessionRequest $request, OpenCashSession $service): JsonResponse { return response()->json(['data' => $service->execute(app(Business::class), $request->user(), $request->validated())->load('register')], 201); }
    public function movement(StoreCashMovementRequest $request, string $session, RecordCashMovement $service): JsonResponse { $record = CashSession::query()->forBusiness(app(Business::class))->whereKey($session)->firstOrFail(); return response()->json(['data' => $service->execute(app(Business::class), $request->user(), $record, $request->validated())], 201); }
    public function closeSession(CloseCashSessionRequest $request, string $session, CloseCashSession $service): JsonResponse { $record = CashSession::query()->forBusiness(app(Business::class))->whereKey($session)->firstOrFail(); return response()->json(['data' => $service->execute(app(Business::class), $request->user(), $record, $request->validated())]); }
    public function collect(CollectPaymentRequest $request, string $order, CollectPayment $service): JsonResponse { $record = Order::query()->forBusiness(app(Business::class))->whereKey($order)->firstOrFail(); return response()->json(['data' => $service->execute(app(Business::class), $request->user(), $record, $request->validated())], 201); }

    public function refund(RefundPaymentRequest $request, string $payment, RefundPayment $service): JsonResponse
    {
        $record = Payment::query()->forBusiness(app(Business::class))->with('order')->whereKey($payment)->firstOrFail();
        return response()->json(['data' => $service->execute(app(Business::class), $request->user(), $record, $request->validated())], 201);
    }
}
