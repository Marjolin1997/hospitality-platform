<?php

use App\Services\Fiscalization\FiscalPaymentMapper;

test('internal payments map to Albanian fiscal payment enums and invoice families', function (): void {
    $mapper = app(FiscalPaymentMapper::class);

    expect($mapper->map('cash'))->toMatchArray(['code'=>'BANKNOTE','invoice_type'=>'CASH'])
        ->and($mapper->map('card'))->toMatchArray(['code'=>'CARD','invoice_type'=>'CASH'])
        ->and($mapper->map('bank_transfer'))->toMatchArray(['code'=>'ACCOUNT','invoice_type'=>'NONCASH'])
        ->and($mapper->map('other'))->toMatchArray(['code'=>'OTHER','invoice_type'=>'NONCASH'])
        ->and($mapper->invoiceType([(object)['method'=>'cash'],(object)['method'=>'card']]))->toBe('CASH')
        ->and($mapper->invoiceType([(object)['method'=>'bank_transfer'],(object)['method'=>'other']]))->toBe('NONCASH')
        ->and($mapper->invoiceType([(object)['method'=>'cash'],(object)['method'=>'bank_transfer']]))->toBeNull()
        ->and($mapper->mixesCashAndNonCash([(object)['method'=>'cash'],(object)['method'=>'bank_transfer']]))->toBeTrue();
});
