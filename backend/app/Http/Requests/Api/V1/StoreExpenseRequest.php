<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Business;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreExpenseRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $business = app(Business::class);

        return [
            'location_id' => ['nullable','string', Rule::exists('locations','id')->where(fn ($q) => $q->where('business_id',$business->id)->where('is_active',true))],
            'category' => ['required','string','max:80'],
            'description' => ['required','string','max:255'],
            'amount' => ['required','decimal:0,4','gt:0','max:99999999999999.9999'],
            'expense_date' => ['required','date','before_or_equal:today'],
        ];
    }
}
