<?php

namespace App\Jobs;

use App\Services\Fiscal\FiscalSubmissionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Dispatched onto the 'fiscal' queue (see docker/supervisor/supervisord.conf -
 * dedicated workers so a slow/unreachable FBR endpoint never starves other app
 * jobs). Retry timing is NOT governed by Laravel's own backoff: the exponential
 * backoff schedule and the give-up threshold both live in fiscal_outbox
 * (FiscalSubmissionService), which is the durable, dashboard-visible source of
 * truth. $tries here is just a high safety ceiling in case a bug caused infinite
 * release()s - it should never actually be hit before fiscal.max_retry_attempts
 * marks the outbox row failed_permanent and this job stops releasing itself.
 */
class FiscalizeInvoiceJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $tries = 100;
    public int $timeout = 90;

    public function __construct(public readonly int $outboxId)
    {
        $this->onQueue('fiscal');
    }

    public function handle(FiscalSubmissionService $service): void
    {
        $outcome = $service->submit($this->outboxId, $this->workerId());

        if ($outcome->shouldRetry()) {
            $this->release($outcome->retryDelaySeconds);
        }
    }

    private function workerId(): string
    {
        return gethostname() . ':' . getmypid();
    }
}
