<?php

use App\Models\Business;
use App\Models\Location;
use App\Models\User;
use App\Services\Invoicing\IssueInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function foreignInvoiceFixture(): array
{
    $business = Business::query()->create([
        'name' => 'Foreign Invoice Test',
        'legal_name' => 'Foreign Invoice Test SHPK',
        'tax_number' => 'L12345678A',
        'currency' => 'ALL',
        'timezone' => 'Europe/Tirane',
        'status' => 'active',
    ]);

    $location = Location::query()->create([
        'business_id' => $business->id,
        'name' => 'Main',
        'code' => 'MAIN',
        'type' => 'bar',
        'address' => 'Tirane',
        'is_active' => true,
    ]);

    $user = User::query()->create([
        'name' => 'Invoice User',
        'email' => Str::lower(Str::random(12)).'@example.test',
        'password' => bcrypt('password'),
    ]);

    $orderId = (string) Str::ulid();
    DB::table('orders')->insert([
        'id' => $orderId,
        'business_id' => $business->id,
        'location_id' => $location->id,
        'venue_table_id' => null,
        'opened_by_user_id' => $user->id,
        'number' => 'FX-ORDER-001',
        'type' => 'takeaway',
        'status' => 'paid',
        'currency' => 'ALL',
        'subtotal' => '10000.0000',
        'discount_total' => '0.0000',
        'tax_total' => '2000.0000',
        'grand_total' => '12000.0000',
        'opened_at' => now()->subHour(),
        'closed_at' => now()->subMinutes(30),
        'created_at' => now()->subHour(),
        'updated_at' => now(),
    ]);

    DB::table('order_items')->insert([
        'id' => (string) Str::ulid(),
        'business_id' => $business->id,
        'order_id' => $orderId,
        'product_id' => null,
        'product_name_snapshot' => 'Test service',
        'sku_snapshot' => 'FX-TEST',
        'quantity' => '1.0000',
        'unit_price' => '12000.0000',
        'tax_rate' => '20.0000',
        'line_subtotal' => '10000.0000',
        'line_tax' => '2000.0000',
        'line_total' => '12000.0000',
        'preparation_station' => null,
        'preparation_status' => 'served',
        'note' => null,
        'created_at' => now()->subHour(),
        'updated_at' => now(),
    ]);

    DB::table('payments')->insert([
        'id' => (string) Str::ulid(),
        'business_id' => $business->id,
        'order_id' => $orderId,
        'cash_session_id' => null,
        'collected_by_user_id' => $user->id,
        'method' => 'bank_transfer',
        'status' => 'completed',
        'amount' => '12000.0000',
        'amount_base' => '12000.0000',
        'currency' => 'ALL',
        'base_currency' => 'ALL',
        'exchange_rate' => '1.0000000000',
        'tendered_amount' => null,
        'change_amount' => null,
        'exchange_rate_snapshot' => json_encode(['source' => 'base_currency', 'rate' => '1.0000000000']),
        'idempotency_key' => 'fx-payment-001',
        'external_reference' => 'BANK-FX-001',
        'paid_at' => now()->subMinutes(30),
        'created_at' => now()->subMinutes(30),
        'updated_at' => now(),
    ]);

    return [$business, $location, $user, $orderId];
}

test('foreign invoice freezes exchange rate and dual-currency totals', function (): void {
    [$business, , $user, $orderId] = foreignInvoiceFixture();

    DB::table('exchange_rates')->insert([
        'base_currency' => 'EUR',
        'quote_currency' => 'ALL',
        'rate' => '100.0000000000',
        'source' => 'test-rate',
        'effective_at' => now()->subMinute(),
        'fetched_at' => now()->subMinute(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $invoice = app(IssueInvoice::class)->execute($business, $user, [
        'order_id' => $orderId,
        'customer_name' => 'Foreign Customer',
        'customer_tax_number' => null,
        'cash_register_id' => null,
        'invoice_currency' => 'EUR',
    ]);

    expect($invoice->currency)->toBe('ALL')
        ->and($invoice->invoice_currency)->toBe('EUR')
        ->and((string) $invoice->exchange_rate)->toBe('100.0000000000')
        ->and($invoice->exchange_rate_source)->toBe('test-rate')
        ->and((string) $invoice->grand_total)->toBe('12000.0000')
        ->and((string) $invoice->grand_total_foreign)->toBe('120.0000')
        ->and((string) $invoice->subtotal_foreign)->toBe('100.0000')
        ->and((string) $invoice->tax_total_foreign)->toBe('20.0000');

    $line = DB::table('invoice_lines')->where('invoice_id', $invoice->id)->firstOrFail();

    expect((string) $line->line_total_foreign)->toBe('120.0000')
        ->and((string) $line->line_subtotal_foreign)->toBe('100.0000')
        ->and((string) $line->line_tax_foreign)->toBe('20.0000');

    DB::table('exchange_rates')
        ->where('base_currency', 'EUR')
        ->where('quote_currency', 'ALL')
        ->update(['rate' => '110.0000000000', 'effective_at' => now()]);

    $stored = DB::table('invoices')->where('id', $invoice->id)->firstOrFail();

    expect((string) $stored->exchange_rate)->toBe('100.0000000000')
        ->and((string) $stored->grand_total_foreign)->toBe('120.0000');
});

test('foreign invoice is rejected when no exchange rate exists', function (): void {
    [$business, , $user, $orderId] = foreignInvoiceFixture();

    expect(fn () => app(IssueInvoice::class)->execute($business, $user, [
        'order_id' => $orderId,
        'customer_name' => null,
        'customer_tax_number' => null,
        'cash_register_id' => null,
        'invoice_currency' => 'EUR',
    ]))->toThrow(ValidationException::class);

    expect(DB::table('invoices')->where('order_id', $orderId)->count())->toBe(0);
});
