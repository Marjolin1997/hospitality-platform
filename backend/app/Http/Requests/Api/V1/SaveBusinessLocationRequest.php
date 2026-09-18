<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveBusinessLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name', '')),
            'code' => strtoupper(trim((string) $this->input('code', ''))),
            'address' => $this->filled('address') ? trim((string) $this->input('address')) : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'id' => ['nullable', 'string'],
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/'],
            'type' => ['required', Rule::in(['bar_cafe', 'bar', 'cafe', 'restaurant', 'lounge', 'pub'])],
            'address' => ['nullable', 'string', 'max:500'],
        ];
    }
}
