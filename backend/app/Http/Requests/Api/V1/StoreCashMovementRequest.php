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
            'amount' => ['required', 'decimal:0,4', 'gt:0', 'max:99999999999999.9999'],
            'currency' => ['required', Rule::in(['ALL', 'EUR', 'USD', 'GBP'])],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }
}
