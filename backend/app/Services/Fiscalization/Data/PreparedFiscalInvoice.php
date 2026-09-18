<?php

namespace App\Services\Fiscalization\Data;

use App\Models\FiscalizationProfile;

final readonly class PreparedFiscalInvoice
{
    public function __construct(
        public FiscalInvoiceSubmission $submission,
        public FiscalizationProfile $profile,
    ) {}
}
