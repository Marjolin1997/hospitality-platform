<?php

use App\Services\Fiscalization\FiscalSecretResolver;

test('fiscal secret resolver reads environment references without persisting secret values', function (): void {
    putenv('FISCAL_TEST_SECRET=super-secret-value');

    try {
        expect(app(FiscalSecretResolver::class)->resolve('env:FISCAL_TEST_SECRET'))->toBe('super-secret-value');
    } finally {
        putenv('FISCAL_TEST_SECRET');
    }
});

test('fiscal secret resolver rejects unsafe identifiers and unavailable vault integration', function (): void {
    $resolver = app(FiscalSecretResolver::class);

    expect(fn () => $resolver->resolve('env:../../unsafe'))->toThrow(RuntimeException::class)
        ->and(fn () => $resolver->resolve('vault:path/key'))->toThrow(RuntimeException::class);
});
