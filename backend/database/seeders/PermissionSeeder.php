<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run():void
    {
        $permissions=[
            'orders'=>['orders.view','orders.create','orders.update','orders.send_to_station','orders.prepare','orders.cancel','orders.apply_discount','orders.override_price','orders.split','orders.merge'],
            'payments'=>['payments.collect','payments.refund'],
            'cash'=>['cash_sessions.view','cash_sessions.open','cash_sessions.close','cash_movements.create'],
            'catalog'=>['products.view','products.manage'],
            'inventory'=>['inventory.view','inventory.receive','inventory.transfer','inventory.adjust'],
            'finance'=>['finance.view','expenses.view','expenses.create','expenses.approve'],
            'invoices'=>['invoices.view','invoices.issue'],
            'fiscalization'=>['fiscalization.view','fiscalization.retry'],
            'reports'=>['reports.operational.view','reports.financial.view'],
            'staff'=>['users.view','users.manage','roles.manage'],
            'settings'=>['business.settings.manage'],
        ];
        foreach($permissions as $group=>$keys)foreach($keys as $key)Permission::query()->updateOrCreate(['key'=>$key],['group'=>$group,'description'=>str_replace(['.','_'],' ',$key)]);
    }
}
