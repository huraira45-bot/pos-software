<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'ntn' => $this->ntn,
            'ntn_formatted' => $this->formattedNtn(),
            'cnic' => $this->cnic,
            'cnic_formatted' => $this->formattedCnic(),
            'address' => $this->address,
            'customer_type' => $this->customer_type,
            'atl_status' => $this->atl_status,
            'atl_checked_at' => $this->atl_checked_at?->toIso8601String(),
            'is_active' => (bool) $this->is_active,
        ];
    }
}
