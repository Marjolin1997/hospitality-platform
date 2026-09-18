<?php

namespace App\Services\Fiscalization\Data;

final readonly class FiscalizationGatewayResult
{
    private function __construct(
        public bool $successful,
        public bool $retryable,
        public ?string $requestId,
        public ?string $fic,
        public ?string $verificationUrl,
        public ?string $qrPayload,
        public ?string $errorCode,
        public ?string $errorMessage,
        public ?int $httpStatus,
        public array $metadata,
    ) {}

    public static function success(
        string $fic,
        ?string $requestId = null,
        ?string $verificationUrl = null,
        ?string $qrPayload = null,
        array $metadata = [],
    ): self {
        return new self(true,false,$requestId,$fic,$verificationUrl,$qrPayload,null,null,null,$metadata);
    }

    public static function failure(
        string $errorCode,
        string $errorMessage,
        bool $retryable,
        ?int $httpStatus = null,
        ?string $requestId = null,
        array $metadata = [],
    ): self {
        return new self(false,$retryable,$requestId,null,null,null,$errorCode,$errorMessage,$httpStatus,$metadata);
    }
}
