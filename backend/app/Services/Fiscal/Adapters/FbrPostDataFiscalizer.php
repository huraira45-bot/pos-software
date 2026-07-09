<?php

namespace App\Services\Fiscal\Adapters;

use App\Services\Fiscal\FbrInvoicePayloadBuilder;

/**
 * Posts to FBR's classic Tier-1 IMS "PostData" endpoint - either the production
 * cloud URL or the PRAL sandbox URL, both of which share the identical request/
 * response contract. Which one is used is purely a matter of which endpoint/token
 * this instance was constructed with (see FiscalizerFactory) - never hardcoded.
 */
class FbrPostDataFiscalizer extends AbstractHttpFiscalizer
{
    public function __construct(
        FbrInvoicePayloadBuilder $payloadBuilder,
        string $endpoint,
        ?string $token,
        private readonly string $adapterName,
    ) {
        parent::__construct($payloadBuilder, $endpoint, $token);
    }

    public function name(): string
    {
        return $this->adapterName;
    }
}
