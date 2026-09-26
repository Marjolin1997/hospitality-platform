<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class SetInventoryReorderLevelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'location_id' => ['required', 'string'],
            'reorder_level' => ['required', 'numeric', 'min:0', 'max:999999999.9999'],
        ];
    }
}
