<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateInventoryCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.inventory_count_item_id' => ['required', 'string', 'distinct'],
            'items.*.counted_quantity' => ['nullable', 'numeric', 'min:0', 'max:999999999.9999'],
        ];
    }
}
