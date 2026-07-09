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
            'phone' => ['nullable', 'string', 'max:30'],
            'ntn' => ['nullable', 'digits:7', Rule::unique('customers', 'ntn')->ignore($customerId)],
            'cnic' => ['nullable', 'digits:13', Rule::unique('customers', 'cnic')->ignore($customerId)],
            'address' => ['nullable', 'string'],
            'customer_type' => ['required', Rule::in(['walk_in', 'b2b'])],
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
