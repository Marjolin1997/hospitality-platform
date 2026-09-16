<?php

namespace App\Services\Payments;

use App\Models\CashSession;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class CashSessionReconciler
{
    public function expectedCash(CashSession $session): string
    {
        $expected = BigDecimal::of((string) $session->opening_cash);

        foreach ($session->movements()->orderBy('occurred_at')->get() as $movement) {
            $amount = BigDecimal::of((string) $movement->amount_base);
            $expected = match ($movement->type) {
                'sale', 'cash_in' => $expected->plus($amount),
                'refund', 'cash_out' => $expected->minus($amount),
                default => $expected,
            };
        }

        return (string) $expected->toScale(4, RoundingMode::HALF_UP);
    }
}
