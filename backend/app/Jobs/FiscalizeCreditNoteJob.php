<?php

namespace App\Jobs;

use App\Models\Business;
use App\Models\InvoiceCreditNote;
use App\Services\Fiscalization\FiscalizeCreditNote;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class FiscalizeCreditNoteJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 45;
    public int $uniqueFor = 120;

    public function __construct(
        public readonly string $businessId,
        public readonly string $creditNoteId,
        public readonly bool $subsequentDelivery = false,
    ) {
        $this->onQueue('fiscalization');
    }

    public function uniqueId(): string
    {
        return $this->businessId.':credit:'.$this->creditNoteId;
    }

    public function handle(FiscalizeCreditNote $fiscalize): void
    {
        $business = Business::query()->whereKey($this->businessId)->where('status', 'active')->firstOrFail();

        try {
            $fiscalize->execute($business, $this->creditNoteId, $this->subsequentDelivery);
        } catch (Throwable $e) {
            InvoiceCreditNote::query()
                ->forBusiness($business)
                ->whereKey($this->creditNoteId)
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
