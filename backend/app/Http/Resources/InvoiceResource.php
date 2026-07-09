<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'usin' => (string) $this->usin,
            'invoice_type' => $this->invoice_type,
            'ref_invoice_id' => $this->ref_invoice_id,
            'ref_usin' => $this->refUsinValue(),
            'fbr_invoice_number' => $this->fbr_invoice_number,
            'fiscal_status' => $this->fiscal_status,
            'printed_offline_pending' => (bool) $this->printed_offline_pending,
            'branch_id' => $this->branch_id,
            'terminal_id' => $this->terminal_id,
            'customer_id' => $this->customer_id,
            'non_atl_confirmed' => (bool) $this->non_atl_confirmed,
            'further_tax_waived' => (bool) $this->further_tax_waived,
            'buyer' => [
                'ntn' => $this->buyer_ntn,
                'cnic' => $this->buyer_cnic,
                'name' => $this->buyer_name,
                'phone' => $this->buyer_phone,
            ],
            'total_sale_value' => (string) $this->total_sale_value,
            'total_tax_charged' => (string) $this->total_tax_charged,
            'discount' => (string) $this->discount,
            'further_tax' => (string) $this->further_tax,
            'total_bill_amount' => (string) $this->total_bill_amount,
            'payment_mode' => $this->payment_mode,
            'payment_breakdown' => $this->payment_breakdown,
            'sold_at' => $this->sold_at?->toIso8601String(),
            'synced_at' => $this->synced_at?->toIso8601String(),
            'cashier_id' => $this->cashier_id,
            'items' => InvoiceItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
