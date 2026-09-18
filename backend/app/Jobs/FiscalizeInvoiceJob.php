<?php

namespace App\Jobs;

use App\Models\Business;
use App\Models\Invoice;
use App\Services\Fiscalization\FiscalizeInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class FiscalizeInvoiceJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 45;
    public int $uniqueFor = 120;

    public function __construct(
        public readonly string $businessId,
        public readonly string $invoiceId,
        public readonly bool $subsequentDelivery = false,
    ) {
        $this->onQueue('fiscalization');
    }

    public function uniqueId(): string
    {
        return $this->businessId.':'.$this->invoiceId;
    }

    public function handle(FiscalizeInvoice $fiscalize): void
    {
        $business = Business::query()->whereKey($this->businessId)->where('status', 'active')->firstOrFail();

        try {
            $fiscalize->execute($business, $this->invoiceId, $this->subsequentDelivery);
        } catch (Throwable $e) {
            Invoice::query()
                ->forBusiness($business)
                ->whereKey($this->invoiceId)
                ->where('fiscalization_status', '!=', 'fiscalized')
                ->update([
                    'fiscalization_status' => 'failed',
                    'fiscalization_error' => mb_substr($e->getMessage(), 0, 2000),
                    'updated_at' => now(),
                ]);

            throw $e;
        }
    }
}
