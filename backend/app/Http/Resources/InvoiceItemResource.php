<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_variant_id' => $this->product_variant_id,
            'item_code' => $this->item_code,
            'item_name' => $this->item_name,
            'pct_code' => $this->pct_code,
            'quantity' => (string) $this->quantity,
            'unit_price_excl_tax' => (string) $this->unit_price_excl_tax,
            'tax_rate' => (string) $this->tax_rate,
            'sale_value' => (string) $this->sale_value,
            'tax_charged' => (string) $this->tax_charged,
            'discount' => (string) $this->discount,
            'further_tax' => (string) $this->further_tax,
            'total_amount' => (string) $this->total_amount,
            'invoice_type' => $this->invoice_type,
        ];
    }
}
