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
            'company_name' => $this->company_name,
            'contact_person' => $this->contact_person,
            'phone' => $this->phone,
            'email' => $this->email,
            'ntn' => $this->ntn,
            'ntn_formatted' => $this->formattedNtn(),
            'cnic' => $this->cnic,
            'cnic_formatted' => $this->formattedCnic(),
            'strn' => $this->strn,
            'address' => $this->address,
            'billing_address' => $this->billing_address,
            'shipping_address' => $this->shipping_address,
            'customer_type' => $this->customer_type,
            'payment_terms_days' => $this->payment_terms_days,
            'credit_limit' => (string) $this->credit_limit,
            'opening_balance' => (string) $this->opening_balance,
            'price_level' => $this->price_level,
            'is_active' => (bool) $this->is_active,
            'sales_summary' => $this->when($this->sales_summary !== null, $this->sales_summary),
            'recent_sales' => InvoiceResource::collection($this->whenLoaded('invoices')),
        ];
    }
}
