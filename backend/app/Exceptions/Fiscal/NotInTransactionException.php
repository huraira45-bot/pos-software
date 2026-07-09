<?php

namespace App\Exceptions\Fiscal;

use RuntimeException;

/**
 * UsinGenerator::next() must run inside the same DB transaction that persists the
 * invoice it's numbering. Otherwise a sale that later fails/rolls back would still
 * have permanently consumed (and thus skipped) a sequence value.
 */
class NotInTransactionException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'UsinGenerator::next() must be called inside an open DB transaction so a rolled-back '
            . 'sale cannot skip a USIN value.'
        );
    }
}
