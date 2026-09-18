<?php

namespace App\Services\Fiscalization\Data;

final readonly class FiscalInvoiceSubmission
{
    public function __construct(
        public string $invoiceId,
        public string $businessId,
        public string $environment,
        public string $requestUuid,
        public string $sendDateTime,
        public string $issueDateTime,
        public ?string $subsequentDeliveryType,
        public string $invoiceType,
        public string $invoiceNumber,
        public int $invoiceOrdinal,
        public string $issuerNuis,
        public bool $isIssuerInVat,
        public string $businessUnitCode,
        public ?string $tcrCode,
        public string $operatorCode,
        public string $softwareCode,
        public string $currency,
        public string $totalWithoutVat,
        public string $totalVat,
        public string $totalPrice,
        public string $iic,
        public string $iicSignature,
        public array $seller,
        public array $buyer,
        public array $items,
        public array $sameTaxes,
        public array $payments,
        public string $payloadHash,
        public ?string $correctiveIicRef = null,
        public ?string $correctiveIssueDateTime = null,
    ) {}

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
