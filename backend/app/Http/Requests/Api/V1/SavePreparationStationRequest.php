<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class SavePreparationStationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name', '')),
            'code' => mb_strtolower(trim((string) $this->input('code', ''))),
        ]);
    }

    public function rules(): array
    {
        return [
            'id' => ['nullable', 'string'],
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:32', 'regex:/^[a-z0-9][a-z0-9_-]*$/'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:10000'],
        ];
    }
}
