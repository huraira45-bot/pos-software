<?php

namespace App\Exceptions\Fiscal;

use RuntimeException;

class InvoiceTotalsMismatchException extends RuntimeException
{
    public function __construct(int $invoiceId, string $field, string $computed, string $stored)
    {
        parent::__construct(
            "Invoice #{$invoiceId} totals mismatch on {$field}: sum of items = {$computed}, header = {$stored}. "
            . 'Refusing to submit to FBR.'
        );
    }
}
