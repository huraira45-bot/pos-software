<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'item_code' => $this->item_code,
            'barcode' => $this->barcode,
            'name' => $this->name,
            'description' => $this->description,
            'unit' => $this->unit,
            'pct_code' => $this->pct_code,
            'tax_rate' => (string) $this->tax_rate,
            'price_excl_tax' => (string) $this->price_excl_tax,
            'cost_price' => $this->cost_price !== null ? (string) $this->cost_price : null,
            'track_stock' => (bool) $this->track_stock,
            'reorder_level' => (string) $this->reorder_level,
            'is_active' => (bool) $this->is_active,
            'variants' => $this->whenLoaded('variants', fn () => $this->variants->map(fn ($v) => [
                'id' => $v->id,
                'name' => $v->name,
                'sku' => $v->sku,
                'barcode' => $v->barcode,
                'price_excl_tax' => $v->price_excl_tax !== null ? (string) $v->price_excl_tax : null,
                'is_active' => (bool) $v->is_active,
            ])),
        ];
    }
}
