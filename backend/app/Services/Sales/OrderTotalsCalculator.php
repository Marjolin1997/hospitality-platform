<?php

namespace App\Services\Sales;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class OrderTotalsCalculator
{
    private const SCALE = 4;

    /** @param array<int, array{quantity:string|int|float, unit_price:string|int|float, tax_rate:string|int|float}> $items */
    public function calculate(array $items): array
    {
        $subtotal = BigDecimal::zero();
        $taxTotal = BigDecimal::zero();

        foreach ($items as $item) {
            $quantity = BigDecimal::of((string) $item['quantity']);
            $unitPrice = BigDecimal::of((string) $item['unit_price']);
            $taxRate = BigDecimal::of((string) $item['tax_rate']);

            $lineTotal = $quantity->multipliedBy($unitPrice);
            $taxDivisor = BigDecimal::of('100')->plus($taxRate);
            $lineNet = $taxDivisor->isZero()
                ? $lineTotal
                : $lineTotal->multipliedBy('100')->dividedBy($taxDivisor, self::SCALE, RoundingMode::HALF_UP);
            $lineTax = $lineTotal->minus($lineNet);

            $subtotal = $subtotal->plus($lineNet);
            $taxTotal = $taxTotal->plus($lineTax);
        }

        $grandTotal = $subtotal->plus($taxTotal);

        return [
            'subtotal' => (string) $subtotal->toScale(self::SCALE, RoundingMode::HALF_UP),
            'discount_total' => '0.0000',
            'tax_total' => (string) $taxTotal->toScale(self::SCALE, RoundingMode::HALF_UP),
            'grand_total' => (string) $grandTotal->toScale(self::SCALE, RoundingMode::HALF_UP),
        ];
    }
}
