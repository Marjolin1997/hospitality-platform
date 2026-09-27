<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class ReverseExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'reason' => trim((string) $this->input('reason', '')),
        ]);
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:64'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
