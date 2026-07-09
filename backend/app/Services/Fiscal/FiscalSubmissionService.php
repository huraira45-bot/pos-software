<?php

namespace App\Services\Fiscal;

use App\Exceptions\Fiscal\InvoiceTotalsMismatchException;
use App\Models\FiscalOutbox;
use App\Models\FiscalOutboxAttempt;
use App\Models\Invoice;
use App\Services\Fiscal\DTO\FiscalizationResult;
use App\Services\Fiscal\DTO\SubmissionOutcome;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The single place that actually posts an invoice to FBR (or mock/SDC) and
 * records the outcome. Both the queue worker (FiscalizeInvoiceJob) and the
 * sandbox smoke-test script call this, so behaviour never diverges between
 * "real" retries and manual/CLI runs.
 *
 * Three phases, deliberately NOT wrapped in one transaction:
 *   1. Claim  - short transaction, locks the outbox row FOR UPDATE just long
 *      enough to flip pending->processing. This is the idempotency checkpoint:
 *      a row already `success`, or already claimed and not yet stale, is skipped.
 *   2. Submit - the actual HTTP call to FBR/SDC/mock, held with NO database lock
 *      so a slow/unreachable endpoint (up to fiscal.http_timeout_seconds) never
 *      blocks other connections or exhausts the pool.
 *   3. Record - short transaction, locks the row again to persist the attempt
 *      log and the resulting invoice/outbox state.
 *
 * If a worker dies mid-flight (phase 2), the row is left `processing` with a
 * locked_at timestamp; FiscalOutboxSweepCommand reclaims rows stuck in
 * `processing` past a staleness window back to `pending` so they're retried.
 */
class FiscalSubmissionService
{
    /** How long a row may sit `processing` before another worker may reclaim it. */
    private const PROCESSING_STALE_AFTER_SECONDS = 120;

    public function __construct(private readonly FiscalizerFactory $factory)
    {
    }

    public function submit(int $outboxId, string $workerId = 'cli'): SubmissionOutcome
    {
        $claim = $this->claim($outboxId, $workerId);

        if ($claim === null) {
            return SubmissionOutcome::alreadySynced();
        }

        [$invoice, $attemptNo] = $claim;

        $fiscalizer = $this->factory->forTerminal($invoice->terminal);

        try {
            $result = $fiscalizer->submit($invoice);
        } catch (InvoiceTotalsMismatchException $e) {
            // The payload builder refused to send this invoice to FBR at all -
            // no HTTP call was made. Retrying will never succeed (the data itself
            // is inconsistent), so this is a permanent failure just like a
            // non-retryable 4xx from FBR, not a transient one to back off and
            // retry - otherwise it would loop forever via the crash-recovery
            // sweep without ever surfacing on the compliance dashboard.
            $result = FiscalizationResult::failure(
                requestPayload: [],
                rawResponse: null,
                httpStatus: null,
                durationMs: 0,
                retryable: false,
                errorMessage: $e->getMessage(),
            );
        }

        if (! $result->success) {
            Log::warning('Fiscal submission attempt failed', [
                'invoice_id' => $invoice->id,
                'adapter' => $fiscalizer->name(),
                'attempt' => $attemptNo,
                'error' => $result->errorMessage,
                'http_status' => $result->httpStatus,
            ]);
        }

        return $this->record($outboxId, $workerId, $attemptNo, $fiscalizer->name(), $result);
    }

    /** @return array{0: Invoice, 1: int}|null null means: nothing to do, already synced. */
    private function claim(int $outboxId, string $workerId): ?array
    {
        return DB::transaction(function () use ($outboxId, $workerId) {
            /** @var FiscalOutbox|null $outbox */
            $outbox = FiscalOutbox::query()->whereKey($outboxId)->lockForUpdate()->first();

            if (! $outbox || $outbox->status === FiscalOutbox::STATUS_SUCCESS) {
                return null;
            }

            $staleCutoff = now()->subSeconds(self::PROCESSING_STALE_AFTER_SECONDS);
            if ($outbox->status === FiscalOutbox::STATUS_PROCESSING
                && $outbox->locked_at
                && $outbox->locked_at->isAfter($staleCutoff)) {
                // Another worker is actively handling this row right now - skip.
                return null;
            }

            $outbox->update([
                'status' => FiscalOutbox::STATUS_PROCESSING,
                'locked_by' => $workerId,
                'locked_at' => now(),
            ]);

            $invoice = $outbox->invoice()->with(['items', 'terminal.branch', 'refInvoice'])->firstOrFail();

            return [$invoice, $outbox->attempts + 1];
        });
    }

    private function record(
        int $outboxId,
        string $workerId,
        int $attemptNo,
        string $adapterName,
        FiscalizationResult $result,
    ): SubmissionOutcome {
        return DB::transaction(function () use ($outboxId, $workerId, $attemptNo, $adapterName, $result) {
            /** @var FiscalOutbox $outbox */
            $outbox = FiscalOutbox::query()->whereKey($outboxId)->lockForUpdate()->firstOrFail();

            FiscalOutboxAttempt::create([
                'fiscal_outbox_id' => $outbox->id,
                'attempt_no' => $attemptNo,
                'adapter' => $adapterName,
                // The JSON body never contains the bearer token (that's an HTTP header,
                // not part of this payload), so there is nothing to redact here.
                'request_payload' => $result->requestPayload,
                'response_status_code' => $result->httpStatus,
                'response_payload' => $result->rawResponse,
                'error_message' => $result->errorMessage,
                'duration_ms' => $result->durationMs,
                'created_at' => now(),
            ]);

            if ($result->success) {
                $outbox->invoice()->update([
                    'fbr_invoice_number' => $result->fbrInvoiceNumber,
                    'fiscal_status' => Invoice::FISCAL_SYNCED,
                    'synced_at' => now(),
                ]);
                $outbox->update([
                    'status' => FiscalOutbox::STATUS_SUCCESS,
                    'attempts' => $attemptNo,
                    'last_error' => null,
                    'locked_by' => null,
                    'locked_at' => null,
                ]);

                return SubmissionOutcome::synced();
            }

            $maxAttempts = (int) config('fiscal.max_retry_attempts');

            if (! $result->retryable || $attemptNo >= $maxAttempts) {
                $outbox->update([
                    'status' => FiscalOutbox::STATUS_FAILED_PERMANENT,
                    'attempts' => $attemptNo,
                    'last_error' => $result->errorMessage,
                    'locked_by' => null,
                    'locked_at' => null,
                ]);
                $outbox->invoice()->update(['fiscal_status' => Invoice::FISCAL_FAILED_PERMANENT]);

                return SubmissionOutcome::failedPermanent();
            }

            $delay = $this->backoffSeconds($attemptNo);
            $outbox->update([
                'status' => FiscalOutbox::STATUS_PENDING,
                'attempts' => $attemptNo,
                'next_attempt_at' => now()->addSeconds($delay),
                'last_error' => $result->errorMessage,
                'locked_by' => null,
                'locked_at' => null,
            ]);

            return SubmissionOutcome::retry($delay);
        });
    }

    private function backoffSeconds(int $attempt): int
    {
        $base = (int) config('fiscal.retry_base_delay_seconds');
        $max = (int) config('fiscal.retry_max_delay_seconds');

        return min($base * (2 ** ($attempt - 1)), $max);
    }
}
