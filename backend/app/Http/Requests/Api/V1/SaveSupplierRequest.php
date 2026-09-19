<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class SaveSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name', '')),
            'tax_number' => $this->filled('tax_number') ? trim((string) $this->input('tax_number')) : null,
            'contact_name' => $this->filled('contact_name') ? trim((string) $this->input('contact_name')) : null,
            'email' => $this->filled('email') ? mb_strtolower(trim((string) $this->input('email'))) : null,
            'phone' => $this->filled('phone') ? trim((string) $this->input('phone')) : null,
            'address' => $this->filled('address') ? trim((string) $this->input('address')) : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'id' => ['nullable', 'string'],
            'name' => ['required', 'string', 'max:160'],
            'tax_number' => ['nullable', 'string', 'max:80'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:80'],
            'address' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
