<?php

use App\Models\Business;
use App\Services\Fiscalization\FiscalInvoiceNumberAllocator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('cash fiscal invoice numbers are sequential per TCR and reset by fiscal year', function (): void {
    $business = Business::query()->create([
        'name'=>'Numbering Business','currency'=>'ALL','timezone'=>'Europe/Tirane','status'=>'active',
    ]);
    $allocator = app(FiscalInvoiceNumberAllocator::class);

    $first = $allocator->next($business,'CASH','aa123bb456',CarbonImmutable::parse('2026-09-18T10:00:00+02:00'));
    $second = $allocator->next($business,'CASH','aa123bb456',CarbonImmutable::parse('2026-09-18T11:00:00+02:00'));
    $otherTcr = $allocator->next($business,'CASH','cc123dd456',CarbonImmutable::parse('2026-09-18T12:00:00+02:00'));
    $nextYear = $allocator->next($business,'CASH','aa123bb456',CarbonImmutable::parse('2027-01-01T00:01:00+01:00'));

    expect($first)->toMatchArray(['ordinal'=>1,'number'=>'1/2026/aa123bb456','scope'=>'aa123bb456','year'=>2026])
        ->and($second['number'])->toBe('2/2026/aa123bb456')
        ->and($otherTcr['number'])->toBe('1/2026/cc123dd456')
        ->and($nextYear['number'])->toBe('1/2027/aa123bb456');
});

test('noncash numbering omits TCR and uses its own annual sequence', function (): void {
    $business = Business::query()->create([
        'name'=>'Noncash Numbering','currency'=>'ALL','timezone'=>'Europe/Tirane','status'=>'active',
    ]);
    $allocator = app(FiscalInvoiceNumberAllocator::class);
    $issuedAt = CarbonImmutable::parse('2026-09-18T10:00:00+02:00');

    expect($allocator->next($business,'NONCASH',null,$issuedAt)['number'])->toBe('1/2026')
        ->and($allocator->next($business,'NONCASH',null,$issuedAt)['number'])->toBe('2/2026');
});
