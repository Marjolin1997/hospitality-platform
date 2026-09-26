<?php

namespace App\Http\Requests\Api\V1;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class ReportRangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'location_id' => ['required', 'string'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $from = CarbonImmutable::createFromFormat('Y-m-d', (string) $this->input('from'));
                $to = CarbonImmutable::createFromFormat('Y-m-d', (string) $this->input('to'));

                if ($from->diffInDays($to) > 366) {
                    $validator->errors()->add('to', 'Report ranges cannot exceed 366 days.');
                }
            },
        ];
    }
}
