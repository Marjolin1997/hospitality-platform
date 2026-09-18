<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class CloseCashSessionRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'counted_cash' => ['required', 'decimal:0,4', 'min:0', 'max:99999999999999.9999'],
            'closing_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
