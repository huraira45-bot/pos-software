<?php

namespace App\Services\Compliance;

use App\Jobs\FiscalizeInvoiceJob;
use App\Models\FiscalOutbox;
use App\Models\Invoice;
use App\Models\Terminal;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Backs the admin compliance dashboard: per-terminal FBR sync health, and
 * manual retry of permanently-failed submissions. Never touches invoice data
 * directly except to re-open a failed_permanent outbox row for another attempt -
 * the invoice itself is immutable regardless of fiscal outcome.
 */
class ComplianceService
{
    public function syncHealth(): array
    {
        $thresholdMinutes = (int) config('fiscal.pending_alert_threshold_minutes');

        return Terminal::query()->get()->map(function (Terminal $terminal) use ($thresholdMinutes) {
            $pending = FiscalOutbox::query()
                ->join('invoices', 'invoices.id', '=', 'fiscal_outbox.invoice_id')
                ->where('invoices.terminal_id', $terminal->id)
                ->whereIn('fiscal_outbox.status', [FiscalOutbox::STATUS_PENDING, FiscalOutbox::STATUS_PROCESSING]);

            $oldestPendingAt = (clone $pending)->min('fiscal_outbox.created_at');
            $pendingCount = (clone $pending)->count();

            $failedCount = FiscalOutbox::query()
                ->join('invoices', 'invoices.id', '=', 'fiscal_outbox.invoice_id')
                ->where('invoices.terminal_id', $terminal->id)
                ->where('fiscal_outbox.status', FiscalOutbox::STATUS_FAILED_PERMANENT)
                ->count();

            $lastSuccessfulPostAt = Invoice::query()
                ->where('terminal_id', $terminal->id)
                ->where('fiscal_status', Invoice::FISCAL_SYNCED)
                ->max('synced_at');

            $ageMinutes = $oldestPendingAt ? (int) now()->diffInMinutes($oldestPendingAt, true) : 0;

            return [
                'terminal_id' => $terminal->id,
                'terminal_code' => $terminal->code,
                'branch_id' => $terminal->branch_id,
                'fiscal_mode' => $terminal->effectiveFiscalMode(),
                'pending_count' => $pendingCount,
                'oldest_pending_age_minutes' => $ageMinutes,
                'failed_permanent_count' => $failedCount,
                'last_successful_post_at' => $lastSuccessfulPostAt,
                'is_breaching_threshold' => $ageMinutes > $thresholdMinutes,
                'threshold_minutes' => $thresholdMinutes,
            ];
        })->all();
    }

    public function failedSubmissions(?int $terminalId = null): \Illuminate\Support\Collection
    {
        return FiscalOutbox::query()
            ->where('status', FiscalOutbox::STATUS_FAILED_PERMANENT)
            ->whereHas('invoice', fn ($q) => $terminalId ? $q->where('terminal_id', $terminalId) : $q)
            ->with(['invoice:id,terminal_id,usin,total_bill_amount', 'attemptLogs' => fn ($q) => $q->latest('attempt_no')->limit(1)])
            ->orderByDesc('updated_at')
            ->get();
    }

    public function retry(FiscalOutbox $outbox): void
    {
        if ($outbox->status !== FiscalOutbox::STATUS_FAILED_PERMANENT) {
            throw new RuntimeException('Only permanently-failed submissions can be manually retried.');
        }

        DB::transaction(function () use ($outbox) {
            $outbox->update([
                'status' => FiscalOutbox::STATUS_PENDING,
                'next_attempt_at' => now(),
                'last_error' => null,
            ]);
            $outbox->invoice()->update(['fiscal_status' => Invoice::FISCAL_PENDING]);
        });

        FiscalizeInvoiceJob::dispatch($outbox->id);
    }
}
