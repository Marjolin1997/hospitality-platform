<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class SaveBusinessRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'permissions' => ['required', 'array', 'min:1', 'max:200'],
            'permissions.*' => ['required', 'string', 'distinct', 'exists:permissions,key'],
        ];
    }
}
