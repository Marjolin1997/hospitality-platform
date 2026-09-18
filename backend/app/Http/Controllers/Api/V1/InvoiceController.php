<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IssueInvoiceRequest;
use App\Models\Business;
use App\Services\Invoicing\IssueInvoice;
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

        return response()->json(['data' => $row]);
    }

    public function store(IssueInvoiceRequest $request, IssueInvoice $issue): JsonResponse
    {
        $invoice = $issue->execute(app(Business::class), $request->user(), $request->validated());

        return response()->json(['data' => $invoice], 201);
    }
}
