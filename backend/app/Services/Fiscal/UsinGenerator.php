<?php

namespace App\Services\Fiscal;

use App\Exceptions\Fiscal\NotInTransactionException;
use App\Exceptions\Fiscal\UsinCounterMissingException;
use App\Models\UsinCounter;
use Illuminate\Support\Facades\DB;

/**
 * Generates strictly sequential, gapless, per-terminal Unique Sequential Invoice
 * Numbers (USIN).
 *
 * Design: one row per terminal in usin_counters, locked with SELECT ... FOR UPDATE
 * inside the caller's transaction. Because the increment lives in the *same*
 * transaction as the invoice insert, a rollback (failed fiscalization payload
 * validation, DB error, etc.) undoes the increment too - so a value is only ever
 * consumed once it is durably committed alongside its invoice. This is what makes
 * the sequence gapless under concurrency and crash-safe: on crash, Postgres either
 * committed both the counter and the invoice, or neither.
 *
 * A native Postgres SEQUENCE was considered instead, but sequence nextval() is
 * intentionally non-transactional (it does not roll back), which would violate the
 * "never skipped" requirement whenever a sale aborts after allocating a number.
 * The locked-counter-row pattern is the correct way to get sequence-like behaviour
 * that is also transactional.
 */
class UsinGenerator
{
    public function next(int $terminalId): int
    {
        if (DB::transactionLevel() < 1) {
            throw new NotInTransactionException();
        }

        /** @var UsinCounter|null $counter */
        $counter = UsinCounter::query()
            ->where('terminal_id', $terminalId)
            ->lockForUpdate()
            ->first();

        if (! $counter) {
            throw new UsinCounterMissingException($terminalId);
        }

        $next = $counter->last_value + 1;
        $counter->update(['last_value' => $next]);

        return $next;
    }
}
