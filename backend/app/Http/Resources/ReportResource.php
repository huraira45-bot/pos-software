<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps the standard report envelope ({summary, columns, rows, totals, meta})
 * every ReportingService method returns, so all /reports/* endpoints share one
 * response shape - the frontend's generic ReportView renders any of them
 * without per-report special-casing.
 */
class ReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
