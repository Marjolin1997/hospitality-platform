<?php

namespace App\Services\Payments;

use App\Models\Business;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OpenCashSession
{
    public function execute(Business $business, User $user, array $payload): CashSession
    {
        return DB::transaction(function () use ($business, $user, $payload): CashSession {
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
                'opened_by_user_id' => $user->getKey(),
                'base_currency' => $business->currency,
                'opening_cash' => (string) $payload['opening_cash'],
                'status' => 'open',
                'opened_at' => now(),
            ]);
        }, attempts: 3);
    }
}
