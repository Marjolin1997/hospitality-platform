<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IssueInvoiceRequest;
use App\Http\Requests\Api\V1\IssueInvoiceCreditNoteRequest;
use App\Models\Business;
use App\Services\Invoicing\IssueInvoice;
use App\Services\Invoicing\IssueInvoiceCreditNote;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class InvoiceController extends Controller
{
    public function index(): JsonResponse
    {
        $business = app(Business::class);

        $rows = DB::table('invoices')
            ->where('business_id', $business->id)
            ->orderByDesc('issued_at')
            ->limit(100)
            ->get();

        $credits = DB::table('invoice_credit_notes')
            ->where('business_id', $business->id)
            ->whereIn('invoice_id', $rows->pluck('id'))
            ->get()
            ->keyBy('invoice_id');

        $rows->each(function ($invoice) use ($credits): void {
            $invoice->credit_note = $credits->get($invoice->id);
        });

        return response()->json(['data' => $rows]);
    }

    public function eligibleOrders(): JsonResponse
    {
        $business = app(Business::class);

        $rows = DB::table('orders as o')
            ->leftJoin('invoices as i', function ($join) use ($business): void {
                $join->on('i.order_id', '=', 'o.id')->where('i.business_id', $business->id);
            })
            ->where('o.business_id', $business->id)
            ->where('o.status', 'paid')
            ->whereNull('i.id')
            ->select('o.id','o.number','o.location_id','o.currency','o.grand_total','o.updated_at')
            ->orderByDesc('o.updated_at')
            ->limit(100)
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function show(string $invoice): JsonResponse
    {
        $business = app(Business::class);
        $row = DB::table('invoices')->where('business_id', $business->id)->where('id', $invoice)->first();
        abort_unless($row, 404);

        $row->lines = DB::table('invoice_lines')
            ->where('business_id', $business->id)
            ->where('invoice_id', $invoice)
            ->orderBy('position')
            ->get();

        $row->credit_note = DB::table('invoice_credit_notes')
            ->where('business_id', $business->id)
            ->where('invoice_id', $invoice)
            ->first();

        if ($row->credit_note) {
            $row->credit_note->lines = DB::table('invoice_credit_note_lines')
                ->where('business_id', $business->id)
                ->where('invoice_credit_note_id', $row->credit_note->id)
                ->orderBy('position')
                ->get();
            $row->credit_note->refunded_total = (string) DB::table('payment_refunds')
                ->where('business_id', $business->id)
                ->where('invoice_credit_note_id', $row->credit_note->id)
                ->where('status', 'completed')
                ->sum('amount_base');
        }

        return response()->json(['data' => $row]);
    }

    public function store(IssueInvoiceRequest $request, IssueInvoice $issue): JsonResponse
    {
        $invoice = $issue->execute(app(Business::class), $request->user(), $request->validated());

        return response()->json(['data' => $invoice], 201);
    }

    public function credit(IssueInvoiceCreditNoteRequest $request, string $invoice, IssueInvoiceCreditNote $issue): JsonResponse
    {
        $credit = $issue->execute(app(Business::class), $request->user(), $invoice, $request->validated());

        return response()->json(['data' => $credit], 201);
    }
}
