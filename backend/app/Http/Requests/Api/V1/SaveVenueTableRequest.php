<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class SaveVenueTableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name', '')),
        ]);
    }

    public function rules(): array
    {
        return [
            'id' => ['nullable', 'string'],
            'location_id' => ['required', 'string'],
            'venue_area_id' => ['required', 'string'],
            'name' => ['required', 'string', 'max:64'],
            'capacity' => ['required', 'integer', 'min:1', 'max:100'],
        ];
    }
}
