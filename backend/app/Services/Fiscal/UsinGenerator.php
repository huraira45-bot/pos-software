<?php

namespace App\Services\Fiscal;

use App\Exceptions\Fiscal\InvalidUsinTypeException;
use App\Exceptions\Fiscal\NotInTransactionException;
use App\Exceptions\Fiscal\UsinCounterMissingException;
use App\Models\UsinCounter;
use Illuminate\Support\Facades\DB;

/**
 * Generates strictly sequential, gapless, per-terminal Unique Sequential Invoice
 * Numbers (USIN), formatted with a business-chosen type prefix - SIR-1056 or
 * SS_1034 - that becomes the literal USIN value sent to FBR/PRA.
 *
 * Design: one row per (terminal_id, usin_type) in usin_counters, locked with
 * SELECT ... FOR UPDATE inside the caller's transaction. Because the increment
 * lives in the *same* transaction as the invoice insert, a rollback (failed
 * fiscalization payload validation, DB error, etc.) undoes the increment too -
 * so a value is only ever consumed once it is durably committed alongside its
 * invoice. This is what makes each type's sequence gapless under concurrency
 * and crash-safe: on crash, Postgres either committed both the counter and the
 * invoice, or neither.
 *
 * A native Postgres SEQUENCE was considered instead, but sequence nextval() is
 * intentionally non-transactional (it does not roll back), which would violate the
 * "never skipped" requirement whenever a sale aborts after allocating a number.
 * The locked-counter-row pattern is the correct way to get sequence-like behaviour
 * that is also transactional.
 */
class UsinGenerator
{
    /** SIR uses a hyphen separator, SS uses an underscore - matches the exact format the business already uses in its other systems. */
    public const SEPARATORS = [
        'SIR' => '-',
        'SS' => '_',
    ];

    public function next(int $terminalId, string $usinType): string
    {
        if (! isset(self::SEPARATORS[$usinType])) {
            throw new InvalidUsinTypeException($usinType);
        }

        if (DB::transactionLevel() < 1) {
            throw new NotInTransactionException();
        }

        /** @var UsinCounter|null $counter */
        $counter = UsinCounter::query()
            ->where('terminal_id', $terminalId)
            ->where('usin_type', $usinType)
            ->lockForUpdate()
            ->first();

        if (! $counter) {
            throw new UsinCounterMissingException($terminalId, $usinType);
        }

        $next = $counter->last_value + 1;
        $counter->update(['last_value' => $next]);

        return $usinType . self::SEPARATORS[$usinType] . $next;
    }
}
