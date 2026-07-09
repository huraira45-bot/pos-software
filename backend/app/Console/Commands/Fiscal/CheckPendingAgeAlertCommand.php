<?php

namespace App\Console\Commands\Fiscal;

use App\Events\FiscalOutageThresholdExceeded;
use App\Models\FiscalOutbox;
use App\Models\Terminal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * FBR requires outages to be reported to the Commissioner within 24 hours, so the
 * oldest pending invoice per terminal is checked against
 * fiscal.pending_alert_threshold_minutes (default 1440 = 24h) on a schedule.
 *
 * This command detects and logs/fires the event; wiring an actual delivery
 * channel (email to compliance, Slack, SMS) is a Notification/listener + env
 * config addition, not a change to this detection logic.
 */
class CheckPendingAgeAlertCommand extends Command
{
    protected $signature = 'fiscal:check-pending-age';

    protected $description = 'Alert when any terminal\'s oldest pending FBR submission exceeds the compliance threshold';

    public function handle(): int
    {
        $thresholdMinutes = (int) config('fiscal.pending_alert_threshold_minutes');
        $cutoff = now()->subMinutes($thresholdMinutes);

        $breaches = FiscalOutbox::query()
            ->join('invoices', 'invoices.id', '=', 'fiscal_outbox.invoice_id')
            ->whereIn('fiscal_outbox.status', [FiscalOutbox::STATUS_PENDING, FiscalOutbox::STATUS_PROCESSING])
            ->where('fiscal_outbox.created_at', '<=', $cutoff)
            ->selectRaw('invoices.terminal_id, MIN(fiscal_outbox.created_at) as oldest_pending_at, COUNT(*) as pending_count')
            ->groupBy('invoices.terminal_id')
            ->get();

        foreach ($breaches as $row) {
            $terminal = Terminal::find($row->terminal_id);
            $ageMinutes = (int) now()->diffInMinutes($row->oldest_pending_at, true);

            Log::critical('FBR sync pending age exceeds compliance threshold', [
                'terminal_id' => $row->terminal_id,
                'terminal_code' => $terminal?->code,
                'oldest_pending_at' => $row->oldest_pending_at,
                'age_minutes' => $ageMinutes,
                'pending_count' => $row->pending_count,
                'threshold_minutes' => $thresholdMinutes,
            ]);

            if ($terminal) {
                event(new FiscalOutageThresholdExceeded($terminal, $ageMinutes, (int) $row->pending_count));
            }
        }

        $this->info("Checked pending age thresholds: {$breaches->count()} terminal(s) breaching {$thresholdMinutes}min.");

        return self::SUCCESS;
    }
}
