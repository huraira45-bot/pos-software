<?php

namespace App\Services\Fiscal;

use App\Models\Terminal;
use App\Services\Fiscal\Adapters\FbrPostDataFiscalizer;
use App\Services\Fiscal\Adapters\LocalSdcFiscalizer;
use App\Services\Fiscal\Adapters\MockFiscalizer;
use App\Services\Fiscal\Contracts\FiscalizerContract;
use InvalidArgumentException;

/**
 * Resolves the active IFiscalizer strategy for a terminal. This is the one place
 * that knows how mode names map to concrete adapters/endpoints/tokens - sales,
 * returns, and the outbox worker only ever depend on FiscalizerContract.
 *
 * Adding a future adapter (e.g. FBR Digital Invoicing API v1.12) means adding one
 * case here and one new Adapters/* class; nothing else in the app changes.
 */
class FiscalizerFactory
{
    public function __construct(private readonly FbrInvoicePayloadBuilder $payloadBuilder)
    {
    }

    public function forTerminal(Terminal $terminal): FiscalizerContract
    {
        return match ($terminal->effectiveFiscalMode()) {
            'fbr_cloud' => new FbrPostDataFiscalizer(
                $this->payloadBuilder,
                $terminal->fiscal_endpoint_override ?: config('fiscal.endpoints.fbr_cloud'),
                $terminal->effectiveFiscalToken(),
                'fbr_cloud',
            ),
            'fbr_sandbox' => new FbrPostDataFiscalizer(
                $this->payloadBuilder,
                $terminal->fiscal_endpoint_override ?: config('fiscal.endpoints.fbr_sandbox'),
                $terminal->effectiveFiscalToken(),
                'fbr_sandbox',
            ),
            'local_sdc' => new LocalSdcFiscalizer(
                $this->payloadBuilder,
                $terminal->fiscal_endpoint_override ?: config('fiscal.endpoints.local_sdc'),
                $terminal->effectiveFiscalToken(),
            ),
            'mock' => new MockFiscalizer($this->payloadBuilder),
            default => throw new InvalidArgumentException("Unknown fiscal mode: {$terminal->effectiveFiscalMode()}"),
        };
    }
}
