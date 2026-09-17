<?php
namespace App\Http\Requests\Api\V1;
use Illuminate\Foundation\Http\FormRequest;
class UpdateOrderItemRequest extends FormRequest { public function authorize():bool{return true;} public function rules():array{return ['quantity'=>['sometimes','required','numeric','gt:0','max:999.9999'],'note'=>['sometimes','nullable','string','max:500']];} }
