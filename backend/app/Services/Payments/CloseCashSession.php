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
            $session = CashSession::query()->forBusiness($business)->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            if ($session->status !== 'open') {
                throw ValidationException::withMessages(['session' => 'Only an open cash session can be closed.']);
            }

            $expected = BigDecimal::of($this->reconciler->expectedCash($session));
            $counted = BigDecimal::of((string) $payload['counted_cash'])->toScale(4, RoundingMode::HALF_UP);
            $difference = $counted->minus($expected)->toScale(4, RoundingMode::HALF_UP);

            if (! $difference->isZero() && trim((string) ($payload['closing_note'] ?? '')) === '') {
                throw ValidationException::withMessages([
                    'closing_note' => 'A reconciliation note is required when counted cash differs from expected cash.',
                ]);
            }

            $session->forceFill([
                'closed_by_user_id' => $user->getKey(),
                'expected_cash' => (string) $expected,
                'counted_cash' => (string) $counted,
                'cash_difference' => (string) $difference,
                'status' => 'closed',
                'closed_at' => now(),
                'closing_note' => $payload['closing_note'] ?? null,
            ])->save();

            return $session->fresh(['register', 'movements']);
        }, attempts: 3);
    }
}
