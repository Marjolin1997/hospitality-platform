<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Finance\CurrencyConverter;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExchangeRateController extends Controller
{
    private const SUPPORTED = ['ALL', 'EUR', 'USD', 'GBP'];

    public function index(CurrencyConverter $converter): JsonResponse
    {
        $business = app(\App\Models\Business::class);
        $rates = collect(self::SUPPORTED)->reject(fn (string $currency) => $currency === $business->currency)
            ->mapWithKeys(function (string $currency) use ($business, $converter) {
                try {
                    $conversion = $converter->convert('1', $currency, $business->currency);
                    return [$currency => [
                        'rate' => $conversion['rate'],
                        'source' => $conversion['source'],
                        'effective_at' => $conversion['effective_at'],
                        'inverse' => (bool) ($conversion['inverse'] ?? false),
                    ]];
                } catch (DomainException) {
                    return [$currency => null];
                }
            });

        return response()->json(['data' => ['base_currency' => $business->currency, 'rates' => $rates]]);
    }

    public function convert(Request $request, CurrencyConverter $converter): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'from' => ['required', Rule::in(self::SUPPORTED)],
            'to' => ['required', Rule::in(self::SUPPORTED), 'different:from'],
        ]);

        try {
            $conversion = $converter->convert((string) $validated['amount'], $validated['from'], $validated['to']);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }

        return response()->json(['data' => [
            'from' => $validated['from'],
            'to' => $validated['to'],
            'input_amount' => (string) $validated['amount'],
            ...$conversion,
        ]]);
    }
}
