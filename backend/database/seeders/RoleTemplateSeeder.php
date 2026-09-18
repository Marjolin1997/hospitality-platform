<?php
namespace Database\Seeders;
use App\Models\Permission;use App\Models\Role;use Illuminate\Database\Seeder;
class RoleTemplateSeeder extends Seeder{
 public function run():void{
  $templates=[
   'owner'=>['*'],
   'manager'=>['orders.*','payments.*','cash_sessions.*','cash_movements.create','products.*','inventory.*','finance.view','expenses.*','invoices.*','fiscalization.view','fiscalization.retry','reports.*','users.view'],
   'waiter'=>['orders.view','orders.create','orders.update','orders.send_to_station','orders.split','orders.merge','payments.collect','products.view'],
   'bartender'=>['orders.view','orders.prepare','products.view'],
   'cashier'=>['orders.view','payments.collect','payments.refund','cash_sessions.view','cash_sessions.open','cash_sessions.close','cash_movements.create','invoices.view'],
   'inventory'=>['products.view','inventory.*'],
   'finance'=>['orders.view','finance.view','expenses.*','invoices.*','fiscalization.view','reports.financial.view'],
  ];
  $all=Permission::all();
  foreach($templates as $slug=>$patterns){$role=Role::query()->firstOrCreate(['business_id'=>null,'slug'=>$slug],['name'=>ucfirst($slug),'is_system'=>true]);$ids=$all->filter(function($permission)use($patterns){foreach($patterns as $pattern){if($pattern==='*'||(str_ends_with($pattern,'*')&&str_starts_with($permission->key,substr($pattern,0,-1)))||$permission->key===$pattern)return true;}return false;})->pluck('id');$role->permissions()->sync($ids);}
 }
}
