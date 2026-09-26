<?php

namespace App\Services\Payments;

use App\Models\Business;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RecordCashMovement
{
    public function __construct(private readonly CashSessionReconciler $reconciler) {}

    public function execute(Business $business, User $user, CashSession $session, array $payload): CashMovement
    {
        return DB::transaction(function () use ($business, $user, $session, $payload): CashMovement {
            $session = CashSession::query()
                ->forBusiness($business)
                ->whereKey($session->getKey())
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();

            if (! $session) {
                throw ValidationException::withMessages(['session' => 'An open cash session is required.']);
            }

            $snapshot = $this->snapshot($session, $payload);
            $existing = CashMovement::query()
                ->forBusiness($business)
                ->where('idempotency_key', $payload['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $stored = is_array($existing->request_snapshot)
                    ? $existing->request_snapshot
                    : json_decode((string) $existing->request_snapshot, true, 512, JSON_THROW_ON_ERROR);

                if ((string) $existing->cash_session_id !== (string) $session->getKey() || $stored !== $snapshot) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => 'This idempotency key was already used for a different cash movement request.',
                    ]);
                }

                return $existing;
            }

            if ($payload['currency'] !== $business->currency) {
                throw ValidationException::withMessages([
                    'currency' => 'Cash movements must use the business base currency so the physical drawer can be reconciled exactly.',
                ]);
            }

            $rate = BigDecimal::one();
            $amount = BigDecimal::of((string) $payload['amount'])->toScale(4, RoundingMode::HALF_UP);
            $amountBase = $amount->multipliedBy($rate)->toScale(4, RoundingMode::HALF_UP);

            if ($payload['type'] === 'cash_out') {
                $expected = BigDecimal::of($this->reconciler->expectedCash($session));
                if ($amountBase->isGreaterThan($expected)) {
                    throw ValidationException::withMessages([
                        'amount' => 'Cash out cannot exceed the expected cash currently available in the drawer.',
                    ]);
                }
            }

            return CashMovement::query()->create([
                'business_id' => $business->getKey(),
                'cash_session_id' => $session->getKey(),
                'created_by_user_id' => $user->getKey(),
                'type' => $payload['type'],
                'amount' => (string) $amount,
                'currency' => $payload['currency'],
                'amount_base' => (string) $amountBase,
                'exchange_rate' => (string) $rate,
                'reason' => trim($payload['reason']),
                'idempotency_key' => $payload['idempotency_key'],
                'request_snapshot' => $snapshot,
                'occurred_at' => now(),
            ]);
        }, attempts: 3);
    }

    private function snapshot(CashSession $session, array $payload): array
    {
        return [
            'cash_session_id' => (string) $session->getKey(),
            'type' => (string) $payload['type'],
            'amount' => (string) BigDecimal::of((string) $payload['amount'])->toScale(4, RoundingMode::HALF_UP),
            'currency' => (string) $payload['currency'],
            'reason' => trim((string) $payload['reason']),
        ];
    }
}
