<?php

use App\Services\Invoicing\InvoiceLineAllocator;
use Illuminate\Support\Collection;

test('invoice line allocator distributes gross discount across tax groups exactly', function (): void {
    $items = new Collection([
        (object) [
            'id' => 'line-1',
            'product_name_snapshot' => 'Coffee',
            'sku_snapshot' => 'COF',
            'product_unit_code' => 'C62',
            'product_unit_label' => 'Copë',
            'quantity' => '1.0000',
            'unit_price' => '12.0000',
            'tax_rate' => '20.0000',
            'line_total' => '12.0000',
        ],
        (object) [
            'id' => 'line-2',
            'product_name_snapshot' => 'Voucher',
            'sku_snapshot' => 'VOU',
            'product_unit_code' => 'C62',
            'product_unit_label' => 'Copë',
            'quantity' => '2.0000',
            'unit_price' => '12.0000',
            'tax_rate' => '0.0000',
            'line_total' => '24.0000',
        ],
    ]);

    $result = app(InvoiceLineAllocator::class)->allocate($items, '3.6000');

    expect($result['discount_total'])->toBe('3.6000')
        ->and($result['subtotal'])->toBe('30.6000')
        ->and($result['tax_total'])->toBe('1.8000')
        ->and($result['grand_total'])->toBe('32.4000')
        ->and($result['lines'][0]['discount_percent'])->toBe('10.0000')
        ->and($result['lines'][0]['line_subtotal'])->toBe('9.0000')
        ->and($result['lines'][0]['line_tax'])->toBe('1.8000')
        ->and($result['lines'][0]['line_total'])->toBe('10.8000')
        ->and($result['lines'][1]['discount_percent'])->toBe('10.0000')
        ->and($result['lines'][1]['line_total'])->toBe('21.6000');

    $lineGross = collect($result['lines'])->sum(fn (array $line): float => (float) $line['line_total']);
    expect(number_format($lineGross, 4, '.', ''))->toBe($result['grand_total']);
});

test('invoice line allocator conserves a rounding-sensitive discount exactly', function (): void {
    $items = new Collection([
        (object) [
            'id'=>'a','product_name_snapshot'=>'A','sku_snapshot'=>null,'product_unit_code'=>'C62','product_unit_label'=>'Copë',
            'quantity'=>'1.0000','unit_price'=>'0.3333','tax_rate'=>'20.0000','line_total'=>'0.3333',
        ],
        (object) [
            'id'=>'b','product_name_snapshot'=>'B','sku_snapshot'=>null,'product_unit_code'=>'C62','product_unit_label'=>'Copë',
            'quantity'=>'1.0000','unit_price'=>'0.3333','tax_rate'=>'20.0000','line_total'=>'0.3333',
        ],
        (object) [
            'id'=>'c','product_name_snapshot'=>'C','sku_snapshot'=>null,'product_unit_code'=>'C62','product_unit_label'=>'Copë',
            'quantity'=>'1.0000','unit_price'=>'0.3334','tax_rate'=>'20.0000','line_total'=>'0.3334',
        ],
    ]);

    $result = app(InvoiceLineAllocator::class)->allocate($items, '0.1000');

    $discountedGross = collect($result['lines'])->sum(fn (array $line): float => (float) $line['line_total']);
    expect(number_format($discountedGross, 4, '.', ''))->toBe('0.9000')
        ->and($result['grand_total'])->toBe('0.9000');
});
