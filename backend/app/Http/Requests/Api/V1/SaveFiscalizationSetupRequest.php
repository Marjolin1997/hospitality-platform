<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class SaveFiscalizationSetupRequest extends FormRequest
{
    private const CODE_REGEX = '/^[a-z]{2}[0-9]{3}[a-z]{2}[0-9]{3}$/';

    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'locations' => ['sometimes','array'],
            'locations.*.id' => ['required','string'],
            'locations.*.fiscal_business_unit_code' => ['nullable','string','size:10','regex:'.self::CODE_REGEX],

            'cash_registers' => ['sometimes','array'],
            'cash_registers.*.id' => ['required','string'],
            'cash_registers.*.fiscal_tcr_code' => ['nullable','string','size:10','regex:'.self::CODE_REGEX],

            'operators' => ['sometimes','array'],
            'operators.*.user_id' => ['required','integer'],
            'operators.*.fiscal_operator_code' => ['nullable','string','size:10','regex:'.self::CODE_REGEX],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['locations'=>'fiscal_business_unit_code','cash_registers'=>'fiscal_tcr_code','operators'=>'fiscal_operator_code'] as $group => $field) {
            if (! is_array($this->input($group))) {
                continue;
            }

            $rows = array_map(function ($row) use ($field) {
                if (is_array($row) && array_key_exists($field, $row) && is_string($row[$field])) {
                    $value = strtolower(trim($row[$field]));
                    $row[$field] = $value === '' ? null : $value;
                }

                return $row;
            }, $this->input($group));

            $this->merge([$group => $rows]);
        }
    }
}
