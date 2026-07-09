<?php

namespace App\Exceptions\Sales;

use App\Models\Invoice;
use RuntimeException;

class BuyerInfoRequiredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Buyer NTN/CNIC and name are required for invoices over Rs. ' . number_format(Invoice::BUYER_CAPTURE_THRESHOLD)
        );
    }
}
