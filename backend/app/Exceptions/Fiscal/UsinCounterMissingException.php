<?php

namespace App\Exceptions\Fiscal;

use RuntimeException;

/**
 * Thrown when a terminal has no usin_counters row. Every terminal must get one
 * at creation time (see TerminalObserver) - this is a terminal provisioning bug,
 * not a runtime condition to recover from lazily inside the sale transaction.
 */
class UsinCounterMissingException extends RuntimeException
{
    public function __construct(int $terminalId)
    {
        parent::__construct("No usin_counters row for terminal_id={$terminalId}. Terminal was not provisioned correctly.");
    }
}
