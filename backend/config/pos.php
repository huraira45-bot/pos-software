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
];
