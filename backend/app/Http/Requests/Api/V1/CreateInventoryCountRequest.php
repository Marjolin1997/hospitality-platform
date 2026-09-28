<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class CreateInventoryCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'min:16', 'max:64'],
            'location_id' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:1000'],
            'product_ids' => ['nullable', 'array', 'min:1', 'max:500'],
            'product_ids.*' => ['required', 'string', 'distinct'],
        ];
    }
}
