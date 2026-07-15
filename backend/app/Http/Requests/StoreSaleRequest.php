<?php

namespace App\Http\Requests;

use App\Services\Fiscal\UsinGenerator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'terminal_id' => ['required', 'integer', 'exists:terminals,id'],
            'usin_type' => ['required', Rule::in(array_keys(UsinGenerator::SEPARATORS))],

            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price_excl_tax' => ['nullable', 'numeric', 'gte:0'],
            'items.*.line_discount' => ['nullable', 'numeric', 'gte:0'],
            'items.*.further_tax' => ['nullable', 'numeric', 'gte:0'],

            'bill_discount' => ['nullable', 'numeric', 'gte:0'],

            'tenders' => ['required', 'array', 'min:1'],
            'tenders.*.mode' => ['required', 'integer', Rule::in([1, 2, 3, 4, 6])], // 5=Mixed is derived, not chosen
            'tenders.*.amount' => ['required', 'numeric', 'gt:0'],

            'buyer.ntn' => ['nullable', 'string', 'max:20'],
            'buyer.cnic' => ['nullable', 'string', 'max:20'],
            'buyer.name' => ['nullable', 'string', 'max:255'],
            'buyer.phone' => ['nullable', 'string', 'max:20'],

            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
        ];
    }
}
