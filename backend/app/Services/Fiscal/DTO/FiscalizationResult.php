<?php

namespace App\Services\Fiscal\DTO;

final class FiscalizationResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $fbrInvoiceNumber,
        public readonly ?string $responseCode,
        public readonly ?string $responseMessage,
        public readonly array $requestPayload,
        public readonly ?array $rawResponse,
        public readonly ?int $httpStatus,
        public readonly int $durationMs,
        /** True when the failure looks transient (timeout, 5xx, network) and worth retrying. */
        public readonly bool $retryable,
        public readonly ?string $errorMessage = null,
    ) {
    }

    public static function success(
        string $fbrInvoiceNumber,
        array $requestPayload,
        array $rawResponse,
        int $httpStatus,
        int $durationMs,
    ): self {
        return new self(
            success: true,
            fbrInvoiceNumber: $fbrInvoiceNumber,
            responseCode: (string) ($rawResponse['Code'] ?? '100'),
            responseMessage: $rawResponse['Response'] ?? null,
            requestPayload: $requestPayload,
            rawResponse: $rawResponse,
            httpStatus: $httpStatus,
            durationMs: $durationMs,
            retryable: false,
        );
    }

    public static function failure(
        array $requestPayload,
        ?array $rawResponse,
        ?int $httpStatus,
        int $durationMs,
        bool $retryable,
        string $errorMessage,
        ?string $responseCode = null,
    ): self {
        return new self(
            success: false,
            fbrInvoiceNumber: null,
            responseCode: $responseCode ?? (isset($rawResponse['Code']) ? (string) $rawResponse['Code'] : null),
            responseMessage: $rawResponse['Response'] ?? null,
            requestPayload: $requestPayload,
            rawResponse: $rawResponse,
            httpStatus: $httpStatus,
            durationMs: $durationMs,
            retryable: $retryable,
            errorMessage: $errorMessage,
        );
    }
}
