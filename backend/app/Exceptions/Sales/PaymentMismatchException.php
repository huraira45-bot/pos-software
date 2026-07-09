<?php

namespace App\Exceptions\Sales;

use RuntimeException;

class PaymentMismatchException extends RuntimeException
{
    public function __construct(string $expected, string $received)
    {
        parent::__construct("Payment tenders sum to {$received} but the bill total is {$expected}.");
    }
}
