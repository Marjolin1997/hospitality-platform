<?php

namespace App\Services\Fiscalization;

use App\Models\Business;
use Illuminate\Support\Facades\DB;

final class FiscalizationMonitoring
{
    public function forBusiness(Business $business): array
    {
        $invoiceStates = DB::table('invoices')
            ->where('business_id', $business->id)
            ->select('fiscalization_status', DB::raw('COUNT(*) as total'))
            ->groupBy('fiscalization_status')
            ->pluck('total','fiscalization_status');

        $creditStates = DB::table('invoice_credit_notes')
            ->where('business_id', $business->id)
            ->select('fiscalization_status', DB::raw('COUNT(*) as total'))
            ->groupBy('fiscalization_status')
            ->pluck('total','fiscalization_status');

        $invoiceAttempts24h = DB::table('invoice_fiscalization_attempts')
            ->where('business_id', $business->id)
            ->where('started_at', '>=', now()->subDay());
        $creditAttempts24h = DB::table('credit_note_fiscalization_attempts')
            ->where('business_id', $business->id)
            ->where('started_at', '>=', now()->subDay());

        $attempts24h = (clone $invoiceAttempts24h)->count() + (clone $creditAttempts24h)->count();
        $success24h = (clone $invoiceAttempts24h)->where('status','succeeded')->count()
            + (clone $creditAttempts24h)->where('status','succeeded')->count();
        $failed24h = (clone $invoiceAttempts24h)->where('status','failed')->count()
            + (clone $creditAttempts24h)->where('status','failed')->count();

        $latestInvoiceAttempts = DB::table('invoice_fiscalization_attempts')
            ->where('business_id', $business->id)
            ->select('invoice_id', DB::raw('MAX(attempt_no) as max_attempt'))
            ->groupBy('invoice_id');

        $latestCreditAttempts = DB::table('credit_note_fiscalization_attempts')
            ->where('business_id', $business->id)
            ->select('invoice_credit_note_id', DB::raw('MAX(attempt_no) as max_attempt'))
            ->groupBy('invoice_credit_note_id');

        $invoiceRetryQuery = DB::table('invoice_fiscalization_attempts as a')
            ->joinSub($latestInvoiceAttempts, 'latest', function ($join): void {
                $join->on('latest.invoice_id','=','a.invoice_id')
                    ->on('latest.max_attempt','=','a.attempt_no');
            })
            ->where('a.business_id', $business->id)
            ->where('a.status','retry_pending')
            ->where('a.retryable', true);

        $creditRetryQuery = DB::table('credit_note_fiscalization_attempts as a')
            ->joinSub($latestCreditAttempts, 'latest', function ($join): void {
                $join->on('latest.invoice_credit_note_id','=','a.invoice_credit_note_id')
                    ->on('latest.max_attempt','=','a.attempt_no');
            })
            ->where('a.business_id', $business->id)
            ->where('a.status','retry_pending')
            ->where('a.retryable', true);

        $retryPending = (clone $invoiceRetryQuery)->count() + (clone $creditRetryQuery)->count();

        $oldestRetry = collect([
            (clone $invoiceRetryQuery)->min('a.next_retry_at'),
            (clone $creditRetryQuery)->min('a.next_retry_at'),
        ])->filter()->sort()->first();

        $recentFailures = collect()
            ->concat(DB::table('invoice_fiscalization_attempts as a')
                ->joinSub($latestInvoiceAttempts, 'latest', function ($join): void {
                    $join->on('latest.invoice_id','=','a.invoice_id')
                        ->on('latest.max_attempt','=','a.attempt_no');
                })
                ->join('invoices as i','i.id','=','a.invoice_id')
                ->where('a.business_id',$business->id)
                ->whereIn('a.status',['failed','retry_pending'])
                ->orderByDesc('a.started_at')
                ->limit(10)
                ->get([
                    DB::raw("'invoice' as document_type"),
                    'i.id as document_id','i.number','a.attempt_no','a.status','a.error_code','a.error_message',
                    'a.http_status','a.next_retry_at','a.started_at',
                ]))
            ->concat(DB::table('credit_note_fiscalization_attempts as a')
                ->joinSub($latestCreditAttempts, 'latest', function ($join): void {
                    $join->on('latest.invoice_credit_note_id','=','a.invoice_credit_note_id')
                        ->on('latest.max_attempt','=','a.attempt_no');
                })
                ->join('invoice_credit_notes as c','c.id','=','a.invoice_credit_note_id')
                ->where('a.business_id',$business->id)
                ->whereIn('a.status',['failed','retry_pending'])
                ->orderByDesc('a.started_at')
                ->limit(10)
                ->get([
                    DB::raw("'credit_note' as document_type"),
                    'c.id as document_id','c.number','a.attempt_no','a.status','a.error_code','a.error_message',
                    'a.http_status','a.next_retry_at','a.started_at',
                ]))
            ->sortByDesc('started_at')
            ->take(10)
            ->values();

        return [
            'documents' => [
                'invoices' => $invoiceStates,
                'credit_notes' => $creditStates,
            ],
            'attempts_24h' => [
                'total' => $attempts24h,
                'succeeded' => $success24h,
                'failed' => $failed24h,
                'success_rate' => $attempts24h > 0 ? round(($success24h / $attempts24h) * 100, 1) : null,
            ],
            'retry_backlog' => [
                'count' => $retryPending,
                'oldest_next_retry_at' => $oldestRetry,
            ],
            'recent_failures' => $recentFailures,
            'generated_at' => now()->toISOString(),
        ];
    }
}
