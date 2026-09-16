<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCashMovementRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['cash_in', 'cash_out'])],
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency' => ['required', Rule::in(['ALL', 'EUR', 'USD', 'GBP'])],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
