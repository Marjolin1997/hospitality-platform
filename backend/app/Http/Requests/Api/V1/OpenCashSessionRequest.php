<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class OpenCashSessionRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'cash_register_id' => ['required', 'string'],
            'opening_cash' => ['required', 'numeric', 'min:0'],
        ];
    }
}
