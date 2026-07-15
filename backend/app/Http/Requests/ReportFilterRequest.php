<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared filter validation for every /reports/* endpoint. Authorization is
 * handled by route-level `can:` middleware, not here.
 */
class ReportFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A bare 'to' date (e.g. "2026-07-15") must mean the end of that day, not
     * midnight at its start - otherwise every report silently excludes sales
     * made later that same day. Only append the time if none was given, so an
     * already-full datetime input isn't clobbered.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('to') && ! str_contains((string) $this->input('to'), ':')) {
            $this->merge(['to' => $this->input('to') . ' 23:59:59']);
        }
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'cashier_id' => ['nullable', 'integer', 'exists:users,id'],
            'granularity' => ['nullable', Rule::in(['day', 'month'])],
        ];
    }
}
