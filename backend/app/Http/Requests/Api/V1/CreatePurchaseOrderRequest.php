<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class CreatePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'location_id' => ['required', 'string'],
            'supplier_id' => ['required', 'string'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'string', 'distinct'],
            'items.*.quantity_ordered' => ['required', 'numeric', 'gt:0', 'max:999999999.9999'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:999999999999.9999'],
        ];
    }
}
