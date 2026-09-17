<?php
namespace App\Http\Requests\Api\V1;
use Illuminate\Foundation\Http\FormRequest;
class AddOrderItemRequest extends FormRequest { public function authorize():bool{return true;} public function rules():array{return ['product_id'=>['required','string'],'quantity'=>['required','numeric','gt:0','max:999.9999'],'note'=>['nullable','string','max:500']];} }
