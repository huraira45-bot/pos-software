<?php

namespace App\Services\Fiscal\Contracts;

use App\Models\Invoice;
use App\Services\Fiscal\DTO\FiscalizationResult;

/**
 * Strategy interface for posting an invoice to whichever fiscal authority backend
 * is active for a terminal. Implementations: FbrPostDataFiscalizer (cloud, both
 * production and PRAL sandbox), LocalSdcFiscalizer, MockFiscalizer.
 *
 * A future adapter for FBR's Digital Invoicing API (DI API v1.12) can be added by
 * implementing this same interface - sales/checkout/outbox code never needs to
 * change, only FiscalizerFactory's resolution map.
 */
interface FiscalizerContract
{
    public function submit(Invoice $invoice): FiscalizationResult;

    /** Machine-readable adapter name, stored on fiscal_outbox_attempts for audit. */
    public function name(): string;
}
