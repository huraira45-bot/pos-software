<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Fiscalization Mode
    |--------------------------------------------------------------------------
    |
    | Which IFiscalizer adapter is used when a terminal does not specify its
    | own override. One of: fbr_cloud, fbr_sandbox, local_sdc, mock.
    | NEVER default to fbr_cloud in non-production environments.
    |
    */
    'mode' => env('FISCAL_MODE', 'mock'),

    'endpoints' => [
        'fbr_cloud' => env('FISCAL_FBR_PRODUCTION_URL', 'https://esp.fbr.gov.pk:8244/FBR/v1/api/Live/PostData'),
        'fbr_sandbox' => env('FISCAL_FBR_SANDBOX_URL', 'https://ims.pral.com.pk/ims/sandbox/api/Live/PostData'),
        'local_sdc' => env('FISCAL_LOCAL_SDC_URL', 'http://localhost:8524/api/IMSFiscal/GetInvoiceNumberByModel'),
    ],

    /** Fallback bearer token used when a terminal has no fiscal_token of its own. */
    'default_token' => env('FISCAL_DEFAULT_TOKEN'),

    'http_timeout_seconds' => (int) env('FISCAL_HTTP_TIMEOUT_SECONDS', 15),

    /*
    |--------------------------------------------------------------------------
    | Retry / Backoff
    |--------------------------------------------------------------------------
    |
    | The outbox worker retries with exponential backoff:
    |   delay = min(base_delay * 2^(attempt-1), max_delay)
    | until max_attempts is reached, at which point the outbox row is marked
    | failed_permanent and surfaces on the compliance dashboard for manual
    | intervention. The invoice itself is never mutated or lost.
    |
    */
    'max_retry_attempts' => (int) env('FISCAL_MAX_RETRY_ATTEMPTS', 10),
    'retry_base_delay_seconds' => (int) env('FISCAL_RETRY_BASE_DELAY_SECONDS', 15),
    'retry_max_delay_seconds' => (int) env('FISCAL_RETRY_MAX_DELAY_SECONDS', 3600),

    /*
    |--------------------------------------------------------------------------
    | Compliance Alerting
    |--------------------------------------------------------------------------
    |
    | FBR requires outages to be reported to the Commissioner within 24 hours.
    | The dashboard/alerting job flags any pending invoice older than this.
    |
    */
    'pending_alert_threshold_minutes' => (int) env('FISCAL_PENDING_ALERT_THRESHOLD_MINUTES', 1440),

];
