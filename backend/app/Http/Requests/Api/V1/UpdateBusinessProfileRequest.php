<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateBusinessProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name', '')),
            'legal_name' => $this->filled('legal_name') ? trim((string) $this->input('legal_name')) : null,
            'tax_number' => $this->filled('tax_number') ? trim((string) $this->input('tax_number')) : null,
            'currency' => strtoupper(trim((string) $this->input('currency', ''))),
            'timezone' => trim((string) $this->input('timezone', '')),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:80'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'timezone' => ['required', 'timezone'],
        ];
    }
}
