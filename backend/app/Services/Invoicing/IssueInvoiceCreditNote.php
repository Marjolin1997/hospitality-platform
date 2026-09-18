<?php

namespace App\Services\Invoicing;

use App\Models\Business;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class IssueInvoiceCreditNote
{
    public function execute(Business $business, User $user, string $invoiceId, array $payload): object
    {
        return DB::transaction(function () use ($business, $user, $invoiceId, $payload): object {
            $invoiceLookup = DB::table('invoices')
                ->where('business_id', $business->id)
                ->where('id', $invoiceId)
                ->first();

            abort_unless($invoiceLookup, 404);

            if ($invoiceLookup->order_id) {
                DB::table('orders')
                    ->where('business_id', $business->id)
                    ->where('id', $invoiceLookup->order_id)
                    ->lockForUpdate()
                    ->first();
            }

            $invoice = DB::table('invoices')
                ->where('business_id', $business->id)
                ->where('id', $invoiceId)
                ->lockForUpdate()
                ->first();

            abort_unless($invoice, 404);

            $replay = DB::table('invoice_credit_notes')
                ->where('business_id', $business->id)
                ->where('idempotency_key', $payload['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($replay) {
                $this->assertReplay($replay, $invoice, $payload);
                return $this->withLines($business, $replay);
            }

            $existing = DB::table('invoice_credit_notes')
                ->where('business_id', $business->id)
                ->where('invoice_id', $invoice->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                throw ValidationException::withMessages([
                    'invoice' => 'This invoice already has a full credit note.',
                ]);
            }

            if ($invoice->status !== 'issued') {
                throw ValidationException::withMessages([
                    'invoice' => 'Only an issued invoice can be corrected.',
                ]);
            }

            $lines = DB::table('invoice_lines')
                ->where('business_id', $business->id)
                ->where('invoice_id', $invoice->id)
                ->orderBy('position')
                ->get();

            if ($lines->isEmpty()) {
                throw ValidationException::withMessages([
                    'invoice' => 'The invoice has no immutable lines to credit.',
                ]);
            }

            $operatorCode = DB::table('business_user')
                ->where('business_id', $business->id)
                ->where('user_id', $user->id)
                ->value('fiscal_operator_code');

            $businessNow = CarbonImmutable::now($business->timezone);
            $creditNoteId = (string) Str::ulid();

            DB::table('invoice_credit_notes')->insert([
                'id' => $creditNoteId,
                'business_id' => $business->id,
                'invoice_id' => $invoice->id,
                'created_by_user_id' => $user->id,
                'number' => $this->nextNumber($business, $businessNow),
                'invoice_number_snapshot' => $invoice->number,
                'original_invoice_nslf_snapshot' => $invoice->nslf,
                'fiscal_operator_code_snapshot' => $operatorCode ?: $invoice->fiscal_operator_code_snapshot,
                'fiscal_business_unit_code_snapshot' => $invoice->fiscal_business_unit_code_snapshot,
                'fiscal_tcr_code_snapshot' => $invoice->fiscal_tcr_code_snapshot,
                'original_invoice_issued_at_snapshot' => $invoice->issued_at,
                'status' => 'issued',
                'fiscal_invoice_type' => $invoice->fiscal_invoice_type,
                'currency' => $invoice->currency,
                'subtotal' => $invoice->subtotal,
                'discount_total' => $invoice->discount_total,
                'tax_total' => $invoice->tax_total,
                'grand_total' => $invoice->grand_total,
                'customer_name_snapshot' => $invoice->customer_name,
                'customer_tax_number_snapshot' => $invoice->customer_tax_number,
                'reason' => $payload['reason'],
                'idempotency_key' => $payload['idempotency_key'],
                'issued_at' => $businessNow->utc(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($lines as $line) {
                DB::table('invoice_credit_note_lines')->insert([
                    'id' => (string) Str::ulid(),
                    'business_id' => $business->id,
                    'invoice_credit_note_id' => $creditNoteId,
                    'invoice_line_id' => $line->id,
                    'position' => $line->position,
                    'product_name_snapshot' => $line->product_name_snapshot,
                    'sku_snapshot' => $line->sku_snapshot,
                    'unit_code_snapshot' => $line->unit_code_snapshot,
                    'unit_label_snapshot' => $line->unit_label_snapshot,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unit_price,
                    'discount_percent' => $line->discount_percent,
                    'tax_rate' => $line->tax_rate,
                    'line_subtotal' => $line->line_subtotal,
                    'line_tax' => $line->line_tax,
                    'line_total' => $line->line_total,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $credit = DB::table('invoice_credit_notes')
                ->where('business_id', $business->id)
                ->where('id', $creditNoteId)
                ->firstOrFail();

            return $this->withLines($business, $credit);
        }, attempts: 3);
    }

    private function nextNumber(Business $business, CarbonImmutable $businessNow): string
    {
        $businessDate = $businessNow->toDateString();

        DB::statement(
            'INSERT INTO business_credit_note_counters (business_id, business_date, last_number, created_at, updated_at)
             VALUES (?, ?, LAST_INSERT_ID(1), UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1), updated_at = UTC_TIMESTAMP()',
            [$business->id, $businessDate],
        );

        $sequence = (int) DB::selectOne('SELECT LAST_INSERT_ID() AS sequence')->sequence;

        return sprintf('CN-%s-%04d', $businessNow->format('Ymd'), $sequence);
    }

    private function assertReplay(object $credit, object $invoice, array $payload): void
    {
        $same = (string) $credit->invoice_id === (string) $invoice->id
            && $credit->reason === $payload['reason'];

        if (! $same) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'This idempotency key was already used for a different invoice correction.',
            ]);
        }
    }

    private function withLines(Business $business, object $credit): object
    {
        $credit->lines = DB::table('invoice_credit_note_lines')
            ->where('business_id', $business->id)
            ->where('invoice_credit_note_id', $credit->id)
            ->orderBy('position')
            ->get();

        $credit->refunded_total = (string) DB::table('payment_refunds')
            ->where('business_id', $business->id)
            ->where('invoice_credit_note_id', $credit->id)
            ->where('status', 'completed')
            ->sum('amount_base');

        return $credit;
    }
}
