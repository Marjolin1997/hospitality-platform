<?php

namespace App\Services\Finance;

use App\Models\ExchangeRate;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;

final class CurrencyConverter
{
    private const SCALE = 4;

    public function convert(string $amount, string $from, string $to): array
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($from === $to) {
            return ['amount' => $this->round($amount), 'rate' => '1.0000000000', 'source' => 'identity', 'effective_at' => now()->toISOString()];
        }

        $direct = $this->latest($from, $to);
        if ($direct) {
            return $this->result($amount, $direct->rate, $direct);
        }

        $inverse = $this->latest($to, $from);
        if ($inverse) {
            $rate = BigDecimal::one()->dividedBy((string) $inverse->rate, 10, RoundingMode::HALF_UP);
            return $this->result($amount, (string) $rate, $inverse, true);
        }

        throw new DomainException("No exchange rate is available for {$from}/{$to}.");
    }

    private function latest(string $base, string $quote): ?ExchangeRate
    {
        return ExchangeRate::query()
            ->where('base_currency', $base)
            ->where('quote_currency', $quote)
            ->where('effective_at', '<=', now())
            ->latest('effective_at')
            ->first();
    }

    private function result(string $amount, string $rate, ExchangeRate $record, bool $inverse = false): array
    {
        $converted = BigDecimal::of($amount)->multipliedBy($rate);

        return [
            'amount' => (string) $converted->toScale(self::SCALE, RoundingMode::HALF_UP),
            'rate' => $rate,
            'source' => $record->source,
            'effective_at' => $record->effective_at->toISOString(),
            'inverse' => $inverse,
        ];
    }

    private function round(string $amount): string
    {
        return (string) BigDecimal::of($amount)->toScale(self::SCALE, RoundingMode::HALF_UP);
    }
}
