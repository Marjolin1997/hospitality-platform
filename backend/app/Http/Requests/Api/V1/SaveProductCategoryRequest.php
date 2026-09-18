<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class SaveProductCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name', '')),
            'color' => $this->filled('color') ? strtoupper(trim((string) $this->input('color'))) : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'id' => ['nullable', 'string'],
            'name' => ['required', 'string', 'max:120'],
            'color' => ['nullable', 'regex:/^#[0-9A-F]{6}$/'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:10000'],
        ];
    }
}
