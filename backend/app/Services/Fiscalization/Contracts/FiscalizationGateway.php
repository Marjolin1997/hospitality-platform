<?php

namespace App\Services\Fiscalization\Contracts;

use App\Models\FiscalizationProfile;
use App\Services\Fiscalization\Data\FiscalInvoiceSubmission;
use App\Services\Fiscalization\Data\FiscalizationGatewayResult;

interface FiscalizationGateway
{
    public function registerInvoice(FiscalInvoiceSubmission $submission, FiscalizationProfile $profile): FiscalizationGatewayResult;
}
