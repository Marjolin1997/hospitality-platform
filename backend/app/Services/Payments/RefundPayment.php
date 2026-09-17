<?php

namespace App\Services\Payments;

use App\Models\Business;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RefundPayment
{
    private const SCALE = 4;

    public function execute(Business $business, User $user, Payment $payment, array $payload): PaymentRefund
    {
        return DB::transaction(function () use ($business, $user, $payment, $payload): PaymentRefund {
            $existing = PaymentRefund::query()->forBusiness($business)
                ->where('idempotency_key', $payload['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->assertIdempotentReplay($existing, $payment, $payload);
                return $existing;
            }

            $payment = Payment::query()->forBusiness($business)->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();
            if ($payment->status !== 'completed') {
                throw ValidationException::withMessages(['payment' => 'Only completed payments can be refunded.']);
            }

            $order = $payment->order()->lockForUpdate()->firstOrFail();
            $alreadyRefunded = BigDecimal::of((string) PaymentRefund::query()->forBusiness($business)
                ->where('payment_id', $payment->getKey())->where('status', 'completed')->sum('amount'));
            $requested = BigDecimal::of((string) $payload['amount'])->toScale(self::SCALE, RoundingMode::HALF_UP);
            $refundable = BigDecimal::of((string) $payment->amount)->minus($alreadyRefunded);
            if ($requested->isGreaterThan($refundable)) {
                throw ValidationException::withMessages(['amount' => 'Refund exceeds the remaining refundable amount.']);
            }

            $amountBase = $requested->multipliedBy(BigDecimal::of((string) $payment->exchange_rate))->toScale(self::SCALE, RoundingMode::HALF_UP);
            $session = null;
            if ($payment->method === 'cash') {
                if (empty($payload['cash_session_id'])) {
                    throw ValidationException::withMessages(['cash_session_id' => 'An open cash session is required for a cash refund.']);
                }
                $session = CashSession::query()->forBusiness($business)->whereKey($payload['cash_session_id'])
                    ->where('location_id', $order->location_id)->where('status', 'open')->lockForUpdate()->first();
                if (! $session) {
                    throw ValidationException::withMessages(['cash_session_id' => 'The selected cash session is not available for this refund.']);
                }
            }

            $refund = PaymentRefund::query()->create([
                'business_id' => $business->getKey(), 'payment_id' => $payment->getKey(),
                'cash_session_id' => $session?->getKey(), 'refunded_by_user_id' => $user->getKey(),
                'amount' => (string) $requested, 'amount_base' => (string) $amountBase,
                'currency' => $payment->currency, 'base_currency' => $payment->base_currency,
                'exchange_rate' => $payment->exchange_rate, 'reason' => $payload['reason'],
                'idempotency_key' => $payload['idempotency_key'], 'status' => 'completed', 'refunded_at' => now(),
            ]);

            if ($payment->method === 'cash' && $session) {
                CashMovement::query()->create([
                    'business_id' => $business->getKey(), 'cash_session_id' => $session->getKey(),
                    'created_by_user_id' => $user->getKey(), 'type' => 'refund',
                    'amount' => (string) $requested, 'currency' => $payment->currency,
                    'amount_base' => (string) $amountBase, 'exchange_rate' => $payment->exchange_rate,
                    'reason' => $payload['reason'], 'reference_type' => 'payment_refund',
                    'reference_id' => $refund->getKey(), 'occurred_at' => now(),
                ]);
            }

            $netPaid = $this->netPaidBase($business, $order->getKey());
            $grandTotal = BigDecimal::of((string) $order->grand_total);
            $order->forceFill([
                'status' => $netPaid->isGreaterThanOrEqualTo($grandTotal) ? 'paid' : 'payment_due',
            ])->save();

            return $refund;
        }, attempts: 3);
    }

    private function assertIdempotentReplay(PaymentRefund $existing, Payment $payment, array $payload): void
    {
        $same = (string) $existing->payment_id === (string) $payment->getKey()
            && BigDecimal::of((string) $existing->amount)->compareTo(BigDecimal::of((string) $payload['amount'])) === 0
            && (string) ($existing->cash_session_id ?? '') === (string) ($payload['cash_session_id'] ?? '')
            && $existing->reason === $payload['reason'];

        if (! $same) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'This idempotency key was already used for a different refund request.',
            ]);
        }
    }

    private function netPaidBase(Business $business, string $orderId): BigDecimal
    {
        $paid = BigDecimal::of((string) Payment::query()->forBusiness($business)
            ->where('order_id', $orderId)->where('status', 'completed')->sum('amount_base'));
        $refunded = BigDecimal::of((string) PaymentRefund::query()->forBusiness($business)
            ->where('status', 'completed')
            ->whereHas('payment', fn ($query) => $query->where('order_id', $orderId))
            ->sum('amount_base'));

        return $paid->minus($refunded);
    }
}
