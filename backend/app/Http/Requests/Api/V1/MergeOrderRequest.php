<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class MergeOrderRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100'],
            'source_order_id' => ['required', 'string'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
