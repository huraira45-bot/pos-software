<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Normalize NTN/CNIC to digits-only before validating, so "1234567-8" and "1234567" behave identically. */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'ntn' => $this->filled('ntn') ? preg_replace('/\D/', '', $this->input('ntn')) : null,
            'cnic' => $this->filled('cnic') ? preg_replace('/\D/', '', $this->input('cnic')) : null,
        ]);
    }

    public function rules(): array
    {
        $customerId = $this->route('customer')?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'ntn' => ['nullable', 'digits:7', Rule::unique('customers', 'ntn')->ignore($customerId)],
            'cnic' => ['nullable', 'digits:13', Rule::unique('customers', 'cnic')->ignore($customerId)],
            'strn' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string'],
            'billing_address' => ['nullable', 'string'],
            'shipping_address' => ['nullable', 'string'],
            'customer_type' => ['required', Rule::in(['walk_in', 'b2b'])],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'credit_limit' => ['nullable', 'numeric', 'gte:0'],
            'opening_balance' => ['nullable', 'numeric', 'gte:0'],
            'price_level' => ['nullable', Rule::in(['retail', 'wholesale', 'custom'])],
        ];
    }

    public function messages(): array
    {
        return [
            'ntn.digits' => 'The NTN must be exactly 7 digits.',
            'cnic.digits' => 'The CNIC must be exactly 13 digits.',
        ];
    }
}
