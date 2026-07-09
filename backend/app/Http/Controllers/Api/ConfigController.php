<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;

/**
 * Non-sensitive config values the checkout frontend needs to preview totals
 * accurately client-side (e.g. so the tender amount it auto-fills already
 * accounts for Further Tax before the server confirms it) - never exposes
 * tokens, endpoints, or anything security-relevant.
 */
class ConfigController extends Controller
{
    public function public()
    {
        return response()->json([
            'further_tax_rate_percent' => (float) config('pos.further_tax_rate_percent'),
            'buyer_capture_threshold' => Invoice::BUYER_CAPTURE_THRESHOLD,
            'discount_permission_threshold_percent' => (float) config('pos.discount_permission_threshold_percent'),
        ]);
    }
}
