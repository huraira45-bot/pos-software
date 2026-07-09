<?php

namespace App\Services\Fiscal\Adapters;

use App\Models\Invoice;
use App\Services\Fiscal\Contracts\FiscalizerContract;
use App\Services\Fiscal\DTO\FiscalizationResult;
use App\Services\Fiscal\FbrInvoicePayloadBuilder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Shared HTTP transport for any adapter that speaks the FBR PostData JSON
 * contract: POST the invoice model, expect {"InvoiceNumber","Response","Code"}
 * back with Code "100" on success. FbrPostDataFiscalizer (production + PRAL
 * sandbox) and LocalSdcFiscalizer both extend this - only the endpoint/token
 * differ, per the "never hardcode, configurable per environment/terminal"
 * requirement.
 */
abstract class AbstractHttpFiscalizer implements FiscalizerContract
{
    public function __construct(
        protected readonly FbrInvoicePayloadBuilder $payloadBuilder,
        protected readonly string $endpoint,
        protected readonly ?string $token,
    ) {
    }

    abstract public function name(): string;

    public function submit(Invoice $invoice): FiscalizationResult
    {
        $payload = $this->payloadBuilder->build($invoice);
        $startedAt = microtime(true);

        try {
            $response = Http::withToken($this->token)
                ->acceptJson()
                ->timeout((int) config('fiscal.http_timeout_seconds', 15))
                ->post($this->endpoint, $payload);
        } catch (ConnectionException $e) {
            return FiscalizationResult::failure(
                requestPayload: $payload,
                rawResponse: null,
                httpStatus: null,
                durationMs: $this->elapsedMs($startedAt),
                retryable: true,
                errorMessage: 'Connection error: ' . $e->getMessage(),
            );
        }

        $durationMs = $this->elapsedMs($startedAt);
        $body = $response->json();
        $body = is_array($body) ? $body : null;

        if ($response->successful() && ($body['Code'] ?? null) === '100' && ! empty($body['InvoiceNumber'])) {
            return FiscalizationResult::success(
                fbrInvoiceNumber: (string) $body['InvoiceNumber'],
                requestPayload: $payload,
                rawResponse: $body,
                httpStatus: $response->status(),
                durationMs: $durationMs,
            );
        }

        // 4xx other than 429 means FBR rejected the payload itself (bad data) -
        // retrying the identical payload will never succeed, so it's not retryable.
        $retryable = $response->serverError() || $response->status() === 429 || $body === null;

        return FiscalizationResult::failure(
            requestPayload: $payload,
            rawResponse: $body,
            httpStatus: $response->status(),
            durationMs: $durationMs,
            retryable: $retryable,
            errorMessage: $body['Response'] ?? ('HTTP ' . $response->status() . ' from ' . $this->name()),
            responseCode: isset($body['Code']) ? (string) $body['Code'] : null,
        );
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
