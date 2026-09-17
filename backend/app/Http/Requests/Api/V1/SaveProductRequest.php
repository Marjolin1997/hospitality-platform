<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Business;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $business = app(Business::class);
        $productId = $this->input('id');

        return [
            'id' => ['nullable', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', 'string'],
            'sku' => [
                'nullable', 'string', 'max:64',
                Rule::unique('products', 'sku')
                    ->where(fn ($query) => $query->where('business_id', $business->id))
                    ->ignore($productId),
            ],
            'sale_price' => ['required', 'decimal:0,4', 'min:0'],
            'tax_rate' => ['required', 'decimal:0,4', 'min:0', 'max:100'],
            'preparation_station' => ['nullable', Rule::in(['bar', 'kitchen'])],
            'tracks_stock' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
