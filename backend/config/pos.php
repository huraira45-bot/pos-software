<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Discount Permission Threshold
    |--------------------------------------------------------------------------
    |
    | A line or whole-bill discount whose percentage of the underlying sale
    | value exceeds this figure requires PosPermissions::DISCOUNT_ABOVE_THRESHOLD.
    | Cashiers can grant small discounts on their own; anything larger needs a
    | manager/admin role.
    |
    */
    'discount_permission_threshold_percent' => (float) env('POS_DISCOUNT_PERMISSION_THRESHOLD_PERCENT', 10),

    /** Allow stock quantities to go negative rather than block a sale. */
    'allow_negative_stock' => (bool) env('POS_ALLOW_NEGATIVE_STOCK', true),

    /** Company/brand name shown on receipts above the branch name. */
    'business_name' => env('POS_BUSINESS_NAME', env('APP_NAME', 'POS')),

    /*
    |--------------------------------------------------------------------------
    | Further Tax (non-ATL B2B sales)
    |--------------------------------------------------------------------------
    |
    | Sales Tax Act further tax applies to B2B sales where the buyer is not on
    | FBR's Active Taxpayer List. VERIFY THE CURRENT RATE WITH FBR BEFORE
    | PRODUCTION USE - this default is illustrative, not a confirmed current
    | statutory rate, and further tax rates have changed via SRO amendments
    | historically.
    |
    */
    'further_tax_rate_percent' => (float) env('POS_FURTHER_TAX_RATE_PERCENT', 4),
];
