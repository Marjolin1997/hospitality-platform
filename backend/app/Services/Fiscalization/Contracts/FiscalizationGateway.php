<?php

namespace App\Services\Fiscalization\Contracts;

use App\Services\Fiscalization\Data\FiscalInvoiceSubmission;
use App\Services\Fiscalization\Data\FiscalizationGatewayResult;

interface FiscalizationGateway
{
    public function registerInvoice(FiscalInvoiceSubmission $submission): FiscalizationGatewayResult;
}
