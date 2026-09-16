<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BusinessController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\ExchangeRateController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PaymentController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', fn () => response()->json(['status' => 'ok', 'service' => 'hospitality-api']));
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/businesses', [BusinessController::class, 'index']);

        Route::middleware('tenant')->group(function (): void {
            Route::get('/catalog', [CatalogController::class, 'index'])->middleware('permission:products.view');
            Route::get('/orders', [OrderController::class, 'index'])->middleware('permission:orders.view');
            Route::post('/orders', [OrderController::class, 'store'])->middleware('permission:orders.create');
            Route::get('/orders/{order}', [OrderController::class, 'show'])->middleware('permission:orders.view');
            Route::get('/exchange-rates', [ExchangeRateController::class, 'index'])->middleware('permission:finance.view');
            Route::post('/exchange-rates/convert', [ExchangeRateController::class, 'convert'])->middleware('permission:finance.view');
            Route::get('/cash-registers', [PaymentController::class, 'registers'])->middleware('permission:payments.collect');
            Route::get('/cash-sessions/current', [PaymentController::class, 'currentSession'])->middleware('permission:payments.collect');
            Route::post('/cash-sessions', [PaymentController::class, 'openSession'])->middleware('permission:cash_sessions.open');
            Route::post('/cash-sessions/{session}/movements', [PaymentController::class, 'movement'])->middleware('permission:payments.collect');
            Route::post('/cash-sessions/{session}/close', [PaymentController::class, 'closeSession'])->middleware('permission:cash_sessions.close');
            Route::post('/orders/{order}/payments', [PaymentController::class, 'collect'])->middleware('permission:payments.collect');
            Route::post('/payments/{payment}/refunds', [PaymentController::class, 'refund'])->middleware('permission:payments.refund');
        });
    });
});
