<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveFiscalizationProfileRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'provider' => ['required', Rule::in(['direct_dpt'])],
            'environment' => ['required', Rule::in(['test','production'])],
            'software_code' => ['nullable','string','max:64'],
            'is_issuer_in_vat' => ['nullable','boolean'],
            'endpoint' => ['nullable','url','max:500','starts_with:https://'],
            'certificate_secret_ref' => [
                'nullable',
                'string',
                'max:255',
                'regex:/^(env|vault|secret):[A-Za-z0-9_.:\/\-]+$/',
            ],
            'certificate_password_secret_ref' => [
                'nullable',
                'string',
                'max:255',
                'regex:/^(env|vault|secret):[A-Za-z0-9_.:\/\-]+$/',
            ],
            'clear_certificate_reference' => ['sometimes','boolean'],
            'clear_certificate_password_reference' => ['sometimes','boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['software_code','endpoint','certificate_secret_ref','certificate_password_secret_ref'] as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $value = trim((string) $this->input($field));
                $this->merge([$field => $value === '' ? null : $value]);
            }
        }
    }

    public function messages(): array
    {
        return [
            'certificate_secret_ref.regex' => 'Store only a secret reference such as env:NAME, vault:path/key, or secret:identifier. Never paste a certificate or private key here.',
            'certificate_password_secret_ref.regex' => 'Store only a password secret reference such as env:NAME, vault:path/key, or secret:identifier. Never paste the password here.',
        ];
    }
}
