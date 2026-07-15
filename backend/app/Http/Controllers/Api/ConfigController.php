<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;

/**
 * Non-sensitive config values the checkout frontend needs to preview totals
 * accurately client-side - never exposes tokens, endpoints, or anything
 * security-relevant.
 */
class ConfigController extends Controller
{
    public function public()
    {
        return response()->json([
            'buyer_capture_threshold' => Invoice::BUYER_CAPTURE_THRESHOLD,
            'discount_permission_threshold_percent' => (float) config('pos.discount_permission_threshold_percent'),
        ]);
    }
}
