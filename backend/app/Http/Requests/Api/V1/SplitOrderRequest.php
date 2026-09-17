<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class SplitOrderRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'item_ids' => ['required', 'array', 'min:1', 'max:100'],
            'item_ids.*' => ['required', 'string', 'distinct'],
            'venue_table_id' => ['nullable', 'string'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
