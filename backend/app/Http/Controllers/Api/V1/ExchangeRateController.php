<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ExchangeRate;
use App\Services\Finance\CurrencyConverter;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExchangeRateController extends Controller
{
    private const SUPPORTED = ['ALL', 'EUR', 'USD', 'GBP'];

    public function index(): JsonResponse
    {
        $rates = collect(['EUR', 'USD', 'GBP'])->mapWithKeys(function (string $currency) {
            $rate = ExchangeRate::query()
                ->where('base_currency', $currency)
                ->where('quote_currency', 'ALL')
                ->where('effective_at', '<=', now())
                ->latest('effective_at')
                ->first();

            return [$currency => $rate ? [
                'rate' => $rate->rate,
                'source' => $rate->source,
                'effective_at' => $rate->effective_at,
                'fetched_at' => $rate->fetched_at,
            ] : null];
        });

        return response()->json(['data' => ['base_currency' => 'ALL', 'rates' => $rates]]);
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
