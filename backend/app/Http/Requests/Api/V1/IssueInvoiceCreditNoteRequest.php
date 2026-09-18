<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class IssueInvoiceCreditNoteRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'reason' => ['required','string','min:3','max:500'],
            'idempotency_key' => ['required','string','max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('reason')) {
            $this->merge(['reason' => trim((string) $this->input('reason'))]);
        }
    }
}
