<?php
namespace App\Http\Requests\Api\V1;
use Illuminate\Foundation\Http\FormRequest;
class MoveOrderTableRequest extends FormRequest { public function authorize():bool{return true;} public function rules():array{return ['venue_table_id'=>['required','string'],'reason'=>['required','string','min:3','max:500']];} }
