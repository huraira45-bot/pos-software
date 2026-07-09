<?php

namespace App\Http\Requests;

use App\Support\PosPermissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(PosPermissions::PRODUCT_MANAGE);
    }

    public function rules(): array
    {
        $productId = $this->route('product')?->id;

        return [
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'item_code' => ['required', 'string', 'max:100', Rule::unique('products', 'item_code')->ignore($productId)],
            'barcode' => ['nullable', 'string', 'max:100', Rule::unique('products', 'barcode')->ignore($productId)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'unit' => ['required', 'string', 'max:20'],
            // Pakistan Customs Tariff code - mandatory on every catalog item for FBR payloads.
            'pct_code' => ['required', 'string', 'max:50'],
            'tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'price_excl_tax' => ['required', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'track_stock' => ['boolean'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }
}
