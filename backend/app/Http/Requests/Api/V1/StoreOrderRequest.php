<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'location_id' => ['required', 'string'],
            'venue_table_id' => ['nullable', 'string'],
            'type' => ['required', Rule::in(['table', 'takeaway', 'counter'])],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'string', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999.9999'],
            'items.*.note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
