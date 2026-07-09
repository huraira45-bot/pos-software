<?php

return [

    // Verbatim text mandated by SRO 1006(I)/2021 - do not paraphrase.
    'fbr_footer_statement' => 'Verify this invoice through FBR Tax Asaan Mobile App or SMS at 9966 and win exciting prizes in draw',

    'qr_size_mm' => 7,

    // Path (relative to public/) to the official FBR POS invoicing system logo,
    // printed on every receipt. Ships with a placeholder graphic - replace
    // public/assets/fbr-pos-logo.png with the real asset obtained from FBR/PRAL
    // during onboarding before going live.
    'fbr_logo_path' => env('RECEIPT_FBR_LOGO_PATH', 'assets/fbr-pos-logo.png'),

    'paper_width_mm' => (int) env('RECEIPT_PAPER_WIDTH_MM', 80),

    'currency_symbol' => env('RECEIPT_CURRENCY_SYMBOL', 'Rs.'),
];
