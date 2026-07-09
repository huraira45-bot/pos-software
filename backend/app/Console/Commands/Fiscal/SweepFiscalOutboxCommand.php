<?php

namespace App\Console\Commands\Fiscal;

use App\Jobs\FiscalizeInvoiceJob;
use App\Models\FiscalOutbox;
use Illuminate\Console\Command;

/**
 * Crash-recovery safety net, scheduled every few minutes (see routes/console.php).
 * Normal retries are self-scheduled by FiscalizeInvoiceJob via release(), so this
 * command's job is narrow:
 *
 *  1. Reclaim rows stuck `processing` past the staleness window - this happens
 *     when a worker process dies mid-HTTP-call (OOM, deploy, host reboot) and
 *     never reaches the record() phase to flip status back.
 *  2. Re-dispatch any `pending` row whose next_attempt_at has passed but which,
 *     for whatever reason (e.g. the app process crashed between committing the
 *     invoice/outbox transaction and calling dispatch()), has no job in flight.
 *
 * Both cases are rare in steady state; this only exists so "every finalized
 * invoice eventually reaches FBR" holds even across process crashes.
 */
class SweepFiscalOutboxCommand extends Command
{
    private const PROCESSING_STALE_AFTER_SECONDS = 120;

    protected $signature = 'fiscal:sweep-outbox';

    protected $description = 'Reclaim stale processing rows and re-dispatch overdue pending fiscal_outbox rows';

    public function handle(): int
    {
        $reclaimed = FiscalOutbox::query()
            ->where('status', FiscalOutbox::STATUS_PROCESSING)
            ->where('locked_at', '<=', now()->subSeconds(self::PROCESSING_STALE_AFTER_SECONDS))
            ->update([
                'status' => FiscalOutbox::STATUS_PENDING,
                'locked_by' => null,
                'locked_at' => null,
            ]);

        $due = FiscalOutbox::query()
            ->where('status', FiscalOutbox::STATUS_PENDING)
            ->where(function ($q) {
                $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->pluck('id');

        foreach ($due as $outboxId) {
            FiscalizeInvoiceJob::dispatch($outboxId);
        }

        $this->info("Reclaimed {$reclaimed} stale-processing row(s); dispatched {$due->count()} overdue pending row(s).");

        return self::SUCCESS;
    }
}
