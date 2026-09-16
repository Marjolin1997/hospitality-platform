<?php

namespace App\Services\Payments;

use App\Models\Business;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\ExchangeRate;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CollectPayment
{
    private const SCALE = 4;

    public function execute(Business $business, User $user, Order $order, array $payload): Payment
    {
        return DB::transaction(function () use ($business, $user, $order, $payload): Payment {
            $existing = Payment::query()->forBusiness($business)
                ->where('idempotency_key', $payload['idempotency_key'])->first();
            if ($existing) return $existing;

            $order = Order::query()->forBusiness($business)->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            if (in_array($order->status, ['cancelled', 'closed'], true)) {
                throw ValidationException::withMessages(['order' => 'This order can no longer accept payments.']);
            }

            $session = null;
            if (! empty($payload['cash_session_id'])) {
                $session = CashSession::query()->forBusiness($business)
                    ->whereKey($payload['cash_session_id'])->where('location_id', $order->location_id)
                    ->where('status', 'open')->lockForUpdate()->first();
            }
            if ($payload['method'] === 'cash' && ! $session) {
                throw ValidationException::withMessages(['cash_session_id' => 'An open cash session at this location is required for cash payments.']);
            }

            [$rate, $rateSnapshot] = $this->resolveRate($business, $payload['currency']);
            $amount = BigDecimal::of((string) $payload['amount']);
            $amountBase = $amount->multipliedBy($rate)->toScale(self::SCALE, RoundingMode::HALF_UP);

            $paidBase = BigDecimal::of((string) Payment::query()->forBusiness($business)
                ->where('order_id', $order->getKey())->where('status', 'completed')->sum('amount_base'));
            $remaining = BigDecimal::of((string) $order->grand_total)->minus($paidBase);
            if ($amountBase->isGreaterThan($remaining)) {
                throw ValidationException::withMessages(['amount' => 'Payment exceeds the remaining order balance.']);
            }

            $tendered = isset($payload['tendered_amount']) ? BigDecimal::of((string) $payload['tendered_amount']) : null;
            $change = null;
            if ($payload['method'] === 'cash' && $tendered) {
                if ($tendered->isLessThan($amount)) {
                    throw ValidationException::withMessages(['tendered_amount' => 'Tendered cash cannot be lower than the payment amount.']);
                }
                $change = $tendered->minus($amount)->toScale(self::SCALE, RoundingMode::HALF_UP);
            }

            $payment = Payment::query()->create([
                'business_id' => $business->getKey(), 'order_id' => $order->getKey(),
                'cash_session_id' => $session?->getKey(), 'collected_by_user_id' => $user->getKey(),
                'method' => $payload['method'], 'status' => 'completed', 'amount' => (string) $amount,
                'amount_base' => (string) $amountBase, 'currency' => $payload['currency'],
                'base_currency' => $business->currency, 'exchange_rate' => (string) $rate,
                'tendered_amount' => $tendered ? (string) $tendered : null,
                'change_amount' => $change ? (string) $change : null,
                'exchange_rate_snapshot' => $rateSnapshot, 'idempotency_key' => $payload['idempotency_key'],
                'external_reference' => $payload['external_reference'] ?? null, 'paid_at' => now(),
            ]);

            if ($payload['method'] === 'cash' && $session) {
                CashMovement::query()->create([
                    'business_id' => $business->getKey(), 'cash_session_id' => $session->getKey(),
                    'created_by_user_id' => $user->getKey(), 'type' => 'sale', 'amount' => (string) $amount,
                    'currency' => $payload['currency'], 'amount_base' => (string) $amountBase,
                    'exchange_rate' => (string) $rate, 'reference_type' => 'payment',
                    'reference_id' => $payment->getKey(), 'occurred_at' => now(),
                ]);
            }

            $newPaid = $paidBase->plus($amountBase);
            if ($newPaid->isGreaterThanOrEqualTo(BigDecimal::of((string) $order->grand_total))) {
                $order->forceFill(['status' => 'paid'])->save();
            }

            return $payment;
        }, attempts: 3);
    }

    private function resolveRate(Business $business, string $currency): array
    {
        if ($currency === $business->currency) return [BigDecimal::one(), ['source' => 'base_currency', 'rate' => '1']];

        $rate = ExchangeRate::query()->forBusiness($business)
            ->where('base_currency', $currency)->where('quote_currency', $business->currency)
            ->where('effective_at', '<=', now())->latest('effective_at')->first();
        if (! $rate) {
            throw ValidationException::withMessages(['currency' => 'No current exchange rate is configured for this currency.']);
        }

        return [BigDecimal::of((string) $rate->rate), [
            'exchange_rate_id' => $rate->getKey(), 'base_currency' => $rate->base_currency,
            'quote_currency' => $rate->quote_currency, 'rate' => (string) $rate->rate,
            'source' => $rate->source, 'effective_at' => $rate->effective_at?->toISOString(),
        ]];
    }
}
