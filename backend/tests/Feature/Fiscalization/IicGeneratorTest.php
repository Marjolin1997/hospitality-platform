<?php

use App\Services\Fiscalization\IicGenerator;

test('NSLF IIC generation follows the signed fiscal input contract deterministically', function (): void {
    $resource = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    expect($resource)->not->toBeFalse();

    $privateKeyPem = '';
    expect(openssl_pkey_export($resource, $privateKeyPem))->toBeTrue();

    $service = app(IicGenerator::class);
    $first = $service->generate(
        issuerNuis: 'K02212001T',
        issueDateTime: '2026-09-18T10:37:45+02:00',
        invoiceNumber: '10848/2026/aa123bb456',
        businessUnitCode: 'kp830wi380',
        tcrCode: 'aa123bb456',
        softwareCode: 'sw123code',
        totalPrice: '100.00',
        privateKeyPem: $privateKeyPem,
    );
    $second = $service->generate(
        issuerNuis: 'K02212001T',
        issueDateTime: '2026-09-18T10:37:45+02:00',
        invoiceNumber: '10848/2026/aa123bb456',
        businessUnitCode: 'kp830wi380',
        tcrCode: 'aa123bb456',
        softwareCode: 'sw123code',
        totalPrice: '100.00',
        privateKeyPem: $privateKeyPem,
    );

    expect($first['input'])->toBe('K02212001T|2026-09-18T10:37:45+02:00|10848/2026/aa123bb456|kp830wi380|aa123bb456|sw123code|100.00')
        ->and($first['iic'])->toMatch('/^[0-9A-F]{32}$/')
        ->and($first['signature'])->toMatch('/^[0-9A-F]+$/')
        ->and($second['iic'])->toBe($first['iic'])
        ->and($second['signature'])->toBe($first['signature']);
});

test('NSLF IIC generation rejects incomplete fiscal identity', function (): void {
    $resource = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    $privateKeyPem = '';
    openssl_pkey_export($resource, $privateKeyPem);

    expect(fn () => app(IicGenerator::class)->generate(
        issuerNuis: '',
        issueDateTime: '2026-09-18T10:37:45+02:00',
        invoiceNumber: '1/2026/TCR',
        businessUnitCode: 'UNIT',
        tcrCode: 'TCR',
        softwareCode: 'SOFT',
        totalPrice: '100.00',
        privateKeyPem: $privateKeyPem,
    ))->toThrow(InvalidArgumentException::class);
});
