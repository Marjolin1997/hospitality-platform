<?php

use App\Jobs\FiscalizeInvoiceJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function (): void {
    $latest = DB::table('invoice_fiscalization_attempts')
        ->select('invoice_id', DB::raw('MAX(attempt_no) as max_attempt'))
        ->groupBy('invoice_id');

    DB::table('invoice_fiscalization_attempts as a')
        ->joinSub($latest, 'latest', function ($join): void {
            $join->on('latest.invoice_id', '=', 'a.invoice_id')
                ->on('latest.max_attempt', '=', 'a.attempt_no');
        })
        ->where('a.status', 'retry_pending')
        ->where('a.retryable', true)
        ->whereNotNull('a.next_retry_at')
        ->where('a.next_retry_at', '<=', now())
        ->orderBy('a.next_retry_at')
        ->limit(100)
        ->get(['a.business_id','a.invoice_id'])
        ->each(fn ($row) => FiscalizeInvoiceJob::dispatch(
            (string) $row->business_id,
            (string) $row->invoice_id,
            true,
        ));
})->name('fiscalization-retry-dispatch')->everyMinute()->withoutOverlapping();
