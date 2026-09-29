<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IssueInvoiceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'order_id' => ['required','string'],
            'customer_name' => ['nullable','string','max:255'],
            'customer_tax_number' => ['nullable','string','max:80'],
            'cash_register_id' => ['nullable','string'],
            'invoice_currency' => ['nullable', Rule::in(['ALL','EUR','USD','GBP'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'customer_name' => $this->filled('customer_name') ? trim((string) $this->input('customer_name')) : null,
            'customer_tax_number' => $this->filled('customer_tax_number') ? trim((string) $this->input('customer_tax_number')) : null,
            'invoice_currency' => $this->filled('invoice_currency') ? strtoupper(trim((string) $this->input('invoice_currency'))) : null,
        ]);
    }
}
