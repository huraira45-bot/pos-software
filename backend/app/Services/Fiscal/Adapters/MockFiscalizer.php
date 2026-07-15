<?php

namespace App\Services\Fiscal\Adapters;

use App\Models\Invoice;
use App\Services\Fiscal\Contracts\FiscalizerContract;
use App\Services\Fiscal\DTO\FiscalizationResult;
use App\Services\Fiscal\FbrInvoicePayloadBuilder;

/**
 * No network call. Used for local dev, the checkout flow before sandbox
 * credentials exist, and every automated test. Always succeeds and fabricates
 * an FBR-shaped invoice number so downstream code (receipts, reconciliation)
 * exercises the exact same format it will see in sandbox/production:
 * XXXXXX-DDMMYYHHMMSS-0001
 */
class MockFiscalizer implements FiscalizerContract
{
    public function __construct(private readonly FbrInvoicePayloadBuilder $payloadBuilder)
    {
    }

    public function submit(Invoice $invoice): FiscalizationResult
    {
        $payload = $this->payloadBuilder->build($invoice);

        // usin is now a prefixed string (e.g. "SIR-1056", "SS_1034"), not a bare
        // integer - pull out just the trailing number for the fabricated suffix.
        preg_match('/(\d+)$/', (string) $invoice->usin, $matches);
        $usinNumber = isset($matches[1]) ? (int) $matches[1] : 0;

        $fbrInvoiceNumber = sprintf(
            '%06d-%s-%04d',
            $invoice->terminal->fbr_pos_id,
            now()->format('dmyHis'),
            $usinNumber % 10000,
        );

        $response = [
            'InvoiceNumber' => $fbrInvoiceNumber,
            'Response' => 'Invoice received successfully',
            'Code' => '100',
        ];

        return FiscalizationResult::success(
            fbrInvoiceNumber: $fbrInvoiceNumber,
            requestPayload: $payload,
            rawResponse: $response,
            httpStatus: 200,
            durationMs: 1,
        );
    }

    public function name(): string
    {
        return 'mock';
    }
}
