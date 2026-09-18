<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class RefundPaymentRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'decimal:0,4', 'gt:0', 'max:99999999999999.9999'],
            'cash_session_id' => ['nullable', 'string'],
            'invoice_credit_note_id' => ['nullable', 'string'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ];
    }
}
