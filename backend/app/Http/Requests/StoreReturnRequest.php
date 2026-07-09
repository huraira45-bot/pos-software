<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'original_invoice_id' => ['required', 'integer', 'exists:invoices,id'],
            'terminal_id' => ['required', 'integer', 'exists:terminals,id'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.invoice_item_id' => ['required', 'integer', 'exists:invoice_items,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],

            'tenders' => ['required', 'array', 'min:1'],
            'tenders.*.mode' => ['required', 'integer', Rule::in([1, 2, 3, 4, 6])],
            'tenders.*.amount' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
