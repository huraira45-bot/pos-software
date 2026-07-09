<?php

namespace App\Services\Fiscal\Adapters;

/**
 * Posts to a local Sales Data Controller (SDC) service running on the same LAN
 * (http://localhost:8524/api/IMSFiscal/GetInvoiceNumberByModel by default).
 *
 * Assumption: this integration targets the same request/response shape as the
 * cloud PostData contract (invoice model in, {InvoiceNumber, Response, Code} out).
 * PRAL's actual SDC payload/response schema was not provided in the brief - if it
 * differs, only this class needs to change (override submit() or the payload
 * builder used) since it is isolated behind FiscalizerContract.
 */
class LocalSdcFiscalizer extends AbstractHttpFiscalizer
{
    public function name(): string
    {
        return 'local_sdc';
    }
}
