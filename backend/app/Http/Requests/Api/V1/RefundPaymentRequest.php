<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class RefundPaymentRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0'],
            'cash_session_id' => ['nullable', 'string'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ];
    }
}
