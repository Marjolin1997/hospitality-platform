<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CollectPaymentRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'cash_session_id' => ['nullable', 'string'],
            'method' => ['required', Rule::in(['cash', 'card', 'bank_transfer', 'other'])],
            'currency' => ['required', Rule::in(['ALL', 'EUR', 'USD', 'GBP'])],
            'amount' => ['required', 'numeric', 'gt:0'],
            'tendered_amount' => ['nullable', 'numeric', 'gt:0'],
            'idempotency_key' => ['required', 'string', 'max:100'],
            'external_reference' => ['nullable', 'string', 'max:255'],
        ];
    }
}
