<?php

namespace App\Services\Fiscalization;

use Carbon\CarbonImmutable;

final class FiscalizationRetryPolicy
{
    public function nextRetryAt(int $attemptNo, ?CarbonImmutable $now = null): CarbonImmutable
    {
        $now ??= CarbonImmutable::now('UTC');
        $minutes = match (true) {
            $attemptNo <= 1 => 1,
            $attemptNo === 2 => 5,
            $attemptNo === 3 => 15,
            $attemptNo === 4 => 60,
            default => 240,
        };

        return $now->addMinutes($minutes);
    }
}
