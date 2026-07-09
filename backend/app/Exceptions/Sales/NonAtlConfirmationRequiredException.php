<?php

namespace App\Exceptions\Sales;

use RuntimeException;

/**
 * Thrown when a B2B customer whose atl_status isn't 'active' is attached to a
 * sale and the cashier hasn't yet confirmed awareness (confirm_non_atl_b2b in
 * the checkout request). The frontend catches this, shows a confirmation
 * dialog, and resubmits with the flag set - Further Tax is then applied
 * automatically unless a permission-holder explicitly waives it.
 */
class NonAtlConfirmationRequiredException extends RuntimeException
{
    public function __construct(string $customerName)
    {
        parent::__construct(
            "{$customerName} is not on FBR's Active Taxpayer List. Confirm to proceed - Further Tax will be applied."
        );
    }
}
