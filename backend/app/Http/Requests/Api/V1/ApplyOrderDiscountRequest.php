<?php
namespace App\Http\Requests\Api\V1;
use Illuminate\Foundation\Http\FormRequest;
class ApplyOrderDiscountRequest extends FormRequest { public function authorize():bool{return true;} public function rules():array{return ['amount'=>['required','numeric','min:0','max:999999999999.9999'],'reason'=>['required','string','min:3','max:500']];} }
