<?php

namespace App\Services\Payments;

use App\Models\Business;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OpenCashSession
{
    public function execute(Business $business, User $user, array $payload): CashSession
    {
        return DB::transaction(function () use ($business, $user, $payload): CashSession {
            DB::table('businesses')
                ->where('id', $business->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $snapshot = [
                'location_id' => (string) $payload['location_id'],
                'cash_register_id' => (string) $payload['cash_register_id'],
                'opening_cash' => (string) BigDecimal::of((string) $payload['opening_cash'])
                    ->toScale(4, RoundingMode::HALF_UP),
            ];

            $existing = CashSession::query()
                ->forBusiness($business)
                ->where('open_idempotency_key', $payload['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $stored = is_array($existing->open_request_snapshot)
                    ? $existing->open_request_snapshot
                    : json_decode((string) $existing->open_request_snapshot, true, 512, JSON_THROW_ON_ERROR);

                if ($stored !== $snapshot) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => 'This idempotency key was already used for a different shift-opening request.',
                    ]);
                }

                return $existing;
            }

            $register = CashRegister::query()
                ->forBusiness($business)
                ->whereKey($payload['cash_register_id'])
                ->where('location_id', $payload['location_id'])
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (! $register) {
                throw ValidationException::withMessages(['cash_register_id' => 'The selected cash register is unavailable.']);
            }

            $alreadyOpen = CashSession::query()
                ->forBusiness($business)
                ->where('cash_register_id', $register->getKey())
                ->where('status', 'open')
                ->lockForUpdate()
                ->exists();

            if ($alreadyOpen) {
                throw ValidationException::withMessages(['cash_register_id' => 'This cash register already has an open session.']);
            }

            return CashSession::query()->create([
                'business_id' => $business->getKey(),
                'location_id' => $register->location_id,
                'cash_register_id' => $register->getKey(),
                'open_idempotency_key' => $payload['idempotency_key'],
                'open_request_snapshot' => $snapshot,
                'opened_by_user_id' => $user->getKey(),
                'base_currency' => $business->currency,
                'opening_cash' => $snapshot['opening_cash'],
                'status' => 'open',
                'opened_at' => now(),
            ]);
        }, attempts: 3);
    }
}
