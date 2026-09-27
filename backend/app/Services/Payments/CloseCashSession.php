<?php

namespace App\Services\Payments;

use App\Models\Business;
use App\Models\CashSession;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CloseCashSession
{
    public function __construct(private readonly CashSessionReconciler $reconciler) {}

    public function execute(Business $business, User $user, CashSession $session, array $payload): CashSession
    {
        return DB::transaction(function () use ($business, $user, $session, $payload): CashSession {
            DB::table('businesses')
                ->where('id', $business->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $session = CashSession::query()
                ->forBusiness($business)
                ->whereKey($session->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $counted = BigDecimal::of((string) $payload['counted_cash'])
                ->toScale(4, RoundingMode::HALF_UP);
            $closingNote = trim((string) ($payload['closing_note'] ?? ''));

            $snapshot = [
                'cash_session_id' => (string) $session->getKey(),
                'counted_cash' => (string) $counted,
                'closing_note' => $closingNote !== '' ? $closingNote : null,
            ];

            $existing = CashSession::query()
                ->forBusiness($business)
                ->where('close_idempotency_key', $payload['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $stored = is_array($existing->close_request_snapshot)
                    ? $existing->close_request_snapshot
                    : json_decode((string) $existing->close_request_snapshot, true, 512, JSON_THROW_ON_ERROR);

                if ((string) $existing->getKey() !== (string) $session->getKey() || $stored !== $snapshot) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => 'This idempotency key was already used for a different shift-closing request.',
                    ]);
                }

                return $existing->fresh(['register', 'movements']);
            }

            if ($session->status !== 'open') {
                throw ValidationException::withMessages(['session' => 'Only an open cash session can be closed.']);
            }

            $expected = BigDecimal::of($this->reconciler->expectedCash($session));
            $difference = $counted->minus($expected)->toScale(4, RoundingMode::HALF_UP);

            if (! $difference->isZero() && mb_strlen($closingNote) < 3) {
                throw ValidationException::withMessages([
                    'closing_note' => 'A reconciliation note of at least 3 characters is required when counted cash differs from expected cash.',
                ]);
            }

            $session->forceFill([
                'closed_by_user_id' => $user->getKey(),
                'expected_cash' => (string) $expected,
                'counted_cash' => (string) $counted,
                'cash_difference' => (string) $difference,
                'status' => 'closed',
                'closed_at' => now(),
                'closing_note' => $snapshot['closing_note'],
                'close_idempotency_key' => $payload['idempotency_key'],
                'close_request_snapshot' => $snapshot,
            ])->save();

            return $session->fresh(['register', 'movements']);
        }, attempts: 3);
    }
}
