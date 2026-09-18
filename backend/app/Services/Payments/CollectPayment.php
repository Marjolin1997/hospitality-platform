<?php

namespace App\Services\Payments;

use App\Models\Business;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Services\Finance\CurrencyConverter;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CollectPayment
{
    public function __construct(private readonly CurrencyConverter $converter) {}

    private const SCALE = 4;
    private const PAYABLE_ORDER_STATES = ['open', 'payment_due'];

    public function execute(Business $business, User $user, Order $order, array $payload): Payment
    {
        return DB::transaction(function () use ($business, $user, $order, $payload): Payment {
            // Every commercial mutation locks the order first. This makes payment vs
            // split/merge/item mutations serialize on one canonical row and also turns
            // same-order idempotency-key races into deterministic replays.
            $order = Order::query()->forBusiness($business)
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $existing = Payment::query()->forBusiness($business)
                ->where('idempotency_key', $payload['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->assertIdempotentReplay($existing, $order, $payload);
                return $existing;
            }

            if (! in_array($order->status, self::PAYABLE_ORDER_STATES, true)) {
                throw ValidationException::withMessages([
                    'order' => "Orders in '{$order->status}' status cannot accept a new payment.",
                ]);
            }

            if ($payload['method'] !== 'cash' && (! empty($payload['cash_session_id']) || array_key_exists('tendered_amount', $payload))) {
                throw ValidationException::withMessages([
                    'method' => 'Cash session and tendered amount are only valid for cash payments.',
                ]);
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
            if ($payload['method'] === 'cash' && $payload['currency'] !== $business->currency) {
                throw ValidationException::withMessages([
                    'currency' => 'Cash payments must use the business base currency so the physical drawer can be reconciled exactly.',
                ]);
            }

            [$rate, $rateSnapshot] = $this->resolveRate($business, $payload['currency']);
            $amount = BigDecimal::of((string) $payload['amount'])->toScale(self::SCALE, RoundingMode::HALF_UP);
            $amountBase = $amount->multipliedBy($rate)->toScale(self::SCALE, RoundingMode::HALF_UP);

            $paidBase = $this->netPaidBase($business, $order);
            $remaining = BigDecimal::of((string) $order->grand_total)->minus($paidBase);
            if (! $remaining->isPositive()) {
                throw ValidationException::withMessages(['order' => 'This order has no outstanding balance.']);
            }
            if ($amountBase->isGreaterThan($remaining)) {
                throw ValidationException::withMessages(['amount' => 'Payment exceeds the remaining order balance.']);
            }

            $tendered = isset($payload['tendered_amount']) ? BigDecimal::of((string) $payload['tendered_amount'])->toScale(self::SCALE, RoundingMode::HALF_UP) : null;
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
            } elseif ($newPaid->isPositive()) {
                $order->forceFill(['status' => 'payment_due'])->save();
            }

            return $payment;
        }, attempts: 3);
    }

    private function assertIdempotentReplay(Payment $existing, Order $order, array $payload): void
    {
        $same = (string) $existing->order_id === (string) $order->getKey()
            && $existing->method === $payload['method']
            && $existing->currency === $payload['currency']
            && BigDecimal::of((string) $existing->amount)->compareTo(BigDecimal::of((string) $payload['amount'])) === 0
            && $this->sameOptionalDecimal($existing->tendered_amount, $payload['tendered_amount'] ?? null)
            && (string) ($existing->cash_session_id ?? '') === (string) ($payload['cash_session_id'] ?? '')
            && (string) ($existing->external_reference ?? '') === (string) ($payload['external_reference'] ?? '');

        if (! $same) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'This idempotency key was already used for a different payment request.',
            ]);
        }
    }

    private function sameOptionalDecimal(mixed $stored, mixed $incoming): bool
    {
        if ($stored === null || $incoming === null) {
            return $stored === null && $incoming === null;
        }

        return BigDecimal::of((string) $stored)->compareTo(BigDecimal::of((string) $incoming)) === 0;
    }

    private function netPaidBase(Business $business, Order $order): BigDecimal
    {
        $paid = BigDecimal::of((string) Payment::query()->forBusiness($business)
            ->where('order_id', $order->getKey())->where('status', 'completed')->sum('amount_base'));
        $refunded = BigDecimal::of((string) PaymentRefund::query()->forBusiness($business)
            ->where('status', 'completed')
            ->whereHas('payment', fn ($query) => $query->where('order_id', $order->getKey()))
            ->sum('amount_base'));
        return $paid->minus($refunded);
    }

    private function resolveRate(Business $business, string $currency): array
    {
        if ($currency === $business->currency) {
            return [BigDecimal::one(), ['source' => 'base_currency', 'rate' => '1.0000000000']];
        }

        try {
            $conversion = $this->converter->convert('1', $currency, $business->currency);
        } catch (\DomainException) {
            throw ValidationException::withMessages([
                'currency' => 'No current exchange rate is configured for this currency.',
            ]);
        }

        return [
            BigDecimal::of((string) $conversion['rate']),
            [
                'base_currency' => $currency,
                'quote_currency' => $business->currency,
                'rate' => (string) $conversion['rate'],
                'source' => $conversion['source'],
                'effective_at' => $conversion['effective_at'],
                'inverse' => (bool) ($conversion['inverse'] ?? false),
            ],
        ];
    }

}
