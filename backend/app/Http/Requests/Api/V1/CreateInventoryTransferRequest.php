<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class CreateInventoryTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['note' => trim((string) $this->input('note', ''))]);
    }

    public function rules(): array
    {
        return [
            'source_location_id' => ['required', 'string', 'different:destination_location_id'],
            'destination_location_id' => ['required', 'string', 'different:source_location_id'],
            'note' => ['required', 'string', 'min:3', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'string', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999.9999'],
        ];
    }
}
