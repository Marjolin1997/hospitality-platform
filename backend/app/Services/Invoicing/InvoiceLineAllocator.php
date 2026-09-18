<?php

namespace App\Services\Invoicing;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class InvoiceLineAllocator
{
    private const SCALE = 4;
    private const CALC_SCALE = 10;

    /**
     * @param Collection<int,object> $items
     * @return array{
     *   lines: array<int,array<string,string|int|null>>,
     *   subtotal:string, tax_total:string, grand_total:string, discount_total:string
     * }
     */
    public function allocate(Collection $items, string $discountTotal): array
    {
        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['order_id' => 'An invoice requires at least one active order item.']);
        }

        $grossTotal = $items->reduce(
            fn (BigDecimal $sum, object $item): BigDecimal => $sum->plus(BigDecimal::of((string) $item->line_total)),
            BigDecimal::zero(),
        )->toScale(self::SCALE, RoundingMode::HALF_UP);

        $discount = BigDecimal::of($discountTotal)->toScale(self::SCALE, RoundingMode::HALF_UP);
        if ($discount->isNegative() || $discount->isGreaterThan($grossTotal)) {
            throw ValidationException::withMessages(['discount_total' => 'Invoice discount is outside the active item total.']);
        }

        $remainingDiscount = $discount;
        $subtotal = BigDecimal::zero();
        $taxTotal = BigDecimal::zero();
        $grandTotal = BigDecimal::zero();
        $lines = [];
        $count = $items->count();

        foreach ($items->values() as $index => $item) {
            $lineGross = BigDecimal::of((string) $item->line_total)->toScale(self::SCALE, RoundingMode::HALF_UP);

            if ($discount->isZero()) {
                $lineDiscount = BigDecimal::zero();
            } elseif ($index === $count - 1) {
                $lineDiscount = $remainingDiscount;
            } else {
                $lineDiscount = $grossTotal->isZero()
                    ? BigDecimal::zero()
                    : $discount
                        ->multipliedBy($lineGross)
                        ->dividedBy($grossTotal, self::CALC_SCALE, RoundingMode::HALF_UP)
                        ->toScale(self::SCALE, RoundingMode::HALF_UP);

                if ($lineDiscount->isGreaterThan($remainingDiscount)) {
                    $lineDiscount = $remainingDiscount;
                }
            }

            $remainingDiscount = $remainingDiscount->minus($lineDiscount);
            $discountedGross = $lineGross->minus($lineDiscount)->toScale(self::SCALE, RoundingMode::HALF_UP);
            $taxRate = BigDecimal::of((string) $item->tax_rate)->toScale(self::SCALE, RoundingMode::HALF_UP);
            $divisor = BigDecimal::of('100')->plus($taxRate);

            $lineNet = $divisor->isZero()
                ? $discountedGross
                : $discountedGross
                    ->multipliedBy('100')
                    ->dividedBy($divisor, self::SCALE, RoundingMode::HALF_UP);
            $lineTax = $discountedGross->minus($lineNet)->toScale(self::SCALE, RoundingMode::HALF_UP);

            $discountPercent = $lineGross->isZero()
                ? BigDecimal::zero()
                : $lineDiscount
                    ->multipliedBy('100')
                    ->dividedBy($lineGross, self::SCALE, RoundingMode::HALF_UP);

            $subtotal = $subtotal->plus($lineNet);
            $taxTotal = $taxTotal->plus($lineTax);
            $grandTotal = $grandTotal->plus($discountedGross);

            $lines[] = [
                'invoice_line_source_id' => (string) $item->id,
                'position' => $index + 1,
                'product_name_snapshot' => (string) $item->product_name_snapshot,
                'sku_snapshot' => $item->sku_snapshot,
                'unit_code_snapshot' => $item->product_unit_code ?: 'C62',
                'unit_label_snapshot' => $item->product_unit_label ?: 'Copë',
                'quantity' => (string) BigDecimal::of((string) $item->quantity)->toScale(self::SCALE, RoundingMode::HALF_UP),
                'unit_price' => (string) BigDecimal::of((string) $item->unit_price)->toScale(self::SCALE, RoundingMode::HALF_UP),
                'discount_percent' => (string) $discountPercent->toScale(self::SCALE, RoundingMode::HALF_UP),
                'tax_rate' => (string) $taxRate,
                'line_subtotal' => (string) $lineNet->toScale(self::SCALE, RoundingMode::HALF_UP),
                'line_tax' => (string) $lineTax,
                'line_total' => (string) $discountedGross,
            ];
        }

        if (! $remainingDiscount->isZero()) {
            throw ValidationException::withMessages(['discount_total' => 'Invoice discount allocation did not conserve the order discount.']);
        }

        return [
            'lines' => $lines,
            'subtotal' => (string) $subtotal->toScale(self::SCALE, RoundingMode::HALF_UP),
            'tax_total' => (string) $taxTotal->toScale(self::SCALE, RoundingMode::HALF_UP),
            'grand_total' => (string) $grandTotal->toScale(self::SCALE, RoundingMode::HALF_UP),
            'discount_total' => (string) $discount,
        ];
    }
}
