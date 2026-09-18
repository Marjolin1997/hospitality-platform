<?php

namespace App\Services\Payments;

use App\Models\Business;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Services\Finance\CurrencyConverter;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RecordCashMovement
{
    public function __construct(private readonly CurrencyConverter $converter) {}

    public function execute(Business $business, User $user, CashSession $session, array $payload): CashMovement
    {
        return DB::transaction(function () use ($business, $user, $session, $payload): CashMovement {
            $session = CashSession::query()->forBusiness($business)->whereKey($session->getKey())->where('status', 'open')->lockForUpdate()->first();
            if (! $session) throw ValidationException::withMessages(['session' => 'An open cash session is required.']);

            $rate = BigDecimal::one();
            if ($payload['currency'] !== $business->currency) {
                try {
                    $conversion = $this->converter->convert('1', $payload['currency'], $business->currency);
                    $rate = BigDecimal::of((string) $conversion['rate']);
                } catch (\DomainException) {
                    throw ValidationException::withMessages(['currency' => 'No current exchange rate is configured for this currency.']);
                }
            }

            $amount = BigDecimal::of((string) $payload['amount']);
            $amountBase = $amount->multipliedBy($rate)->toScale(4, RoundingMode::HALF_UP);

            return CashMovement::query()->create([
                'business_id' => $business->getKey(), 'cash_session_id' => $session->getKey(),
                'created_by_user_id' => $user->getKey(), 'type' => $payload['type'],
                'amount' => (string) $amount, 'currency' => $payload['currency'],
                'amount_base' => (string) $amountBase, 'exchange_rate' => (string) $rate,
                'reason' => $payload['reason'], 'occurred_at' => now(),
            ]);
        }, attempts: 3);
    }
}
