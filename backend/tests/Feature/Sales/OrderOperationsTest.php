<?php

use App\Models\Business;
use App\Models\Location;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);
beforeEach(fn () => $this->seed(PermissionSeeder::class));

function ooContext(): array {
    $b=Business::query()->create(['name'=>'Ops','currency'=>'EUR','timezone'=>'Europe/Berlin','status'=>'active']);
    $l=Location::query()->create(['business_id'=>$b->id,'name'=>'Main','code'=>'MAIN','type'=>'bar','is_active'=>true]);
    $u=User::query()->create(['name'=>'Manager','email'=>Str::lower(Str::random(10)).'@example.test','password'=>bcrypt('password')]);
    $r=Role::query()->create(['business_id'=>$b->id,'name'=>'Manager','slug'=>'ops-'.Str::lower(Str::random(5)),'is_system'=>false]);
    $r->permissions()->sync(Permission::query()->whereIn('key',['orders.view','orders.create','orders.update','orders.send_to_station','orders.apply_discount','orders.override_price'])->pluck('id'));
    $u->businesses()->attach($b->id,['role_id'=>$r->id,'status'=>'active']); Sanctum::actingAs($u);
    return [$b,$l,$u,['X-Business-Id'=>$b->id]];
}
function ooProduct(Business $b,string $name='Coffee',string $price='10.0000'): string { $id=(string)Str::ulid(); DB::table('products')->insert(['id'=>$id,'business_id'=>$b->id,'name'=>$name,'sale_price'=>$price,'tax_rate'=>'0.0000','preparation_station'=>'bar','tracks_stock'=>false,'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]); return $id; }
function ooOrder(Business $b,Location $l,User $u,string $product,string $status='open',?string $table=null): array { $oid=(string)Str::ulid();$iid=(string)Str::ulid(); DB::table('orders')->insert(['id'=>$oid,'business_id'=>$b->id,'location_id'=>$l->id,'venue_table_id'=>$table,'opened_by_user_id'=>$u->id,'number'=>'OPS-'.Str::random(8),'type'=>$table?'table':'takeaway','status'=>$status,'currency'=>'EUR','subtotal'=>'10.0000','discount_total'=>'0.0000','tax_total'=>'0.0000','grand_total'=>'10.0000','opened_at'=>now(),'created_at'=>now(),'updated_at'=>now()]); DB::table('order_items')->insert(['id'=>$iid,'business_id'=>$b->id,'order_id'=>$oid,'product_id'=>$product,'product_name_snapshot'=>'Coffee','quantity'=>'1.0000','unit_price'=>'10.0000','tax_rate'=>'0.0000','line_subtotal'=>'10.0000','line_tax'=>'0.0000','line_total'=>'10.0000','preparation_station'=>'bar','preparation_status'=>'pending','created_at'=>now(),'updated_at'=>now()]); return [$oid,$iid]; }
function ooTable(Business $b,Location $l,string $name): string { $id=(string)Str::ulid(); DB::table('venue_tables')->insert(['id'=>$id,'business_id'=>$b->id,'location_id'=>$l->id,'name'=>$name,'capacity'=>4,'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]); return $id; }

test('unsent items can be added edited and removed with exact total recalculation', function(){ [$b,$l,$u,$h]=ooContext();$p=ooProduct($b);[$o,$i]=ooOrder($b,$l,$u,$p);$p2=ooProduct($b,'Tea','5.0000'); $this->postJson("/api/v1/orders/$o/items",['product_id'=>$p2,'quantity'=>'2'],$h)->assertOk()->assertJsonPath('data.grand_total','20.0000'); $added=DB::table('order_items')->where('order_id',$o)->where('product_id',$p2)->value('id'); $this->patchJson("/api/v1/order-items/$added",['quantity'=>'3'],$h)->assertOk()->assertJsonPath('data.grand_total','25.0000'); $this->deleteJson("/api/v1/order-items/$added",['reason'=>'Guest changed selection'],$h)->assertOk()->assertJsonPath('data.grand_total','10.0000'); expect(DB::table('order_items')->where('id',$added)->value('preparation_status'))->toBe('voided'); });

test('sent items cannot be silently edited or removed', function(){ [$b,$l,$u,$h]=ooContext();$p=ooProduct($b);[$o,$i]=ooOrder($b,$l,$u,$p); DB::table('order_items')->where('id',$i)->update(['preparation_status'=>'sent','sent_at'=>now()]); $this->patchJson("/api/v1/order-items/$i",['quantity'=>'2'],$h)->assertStatus(422); $this->deleteJson("/api/v1/order-items/$i",['reason'=>'Changed after send'],$h)->assertStatus(422); });

test('price override and order discount are permission separated audited and exact', function(){ [$b,$l,$u,$h]=ooContext();$p=ooProduct($b);[$o,$i]=ooOrder($b,$l,$u,$p); $this->putJson("/api/v1/order-items/$i/price",['unit_price'=>'8.5000','reason'=>'Manager approved happy hour'],$h)->assertOk()->assertJsonPath('data.grand_total','8.5000'); $this->putJson("/api/v1/orders/$o/discount",['amount'=>'1.2500','reason'=>'Loyal customer'],$h)->assertOk()->assertJsonPath('data.discount_total','1.2500')->assertJsonPath('data.grand_total','7.2500'); $row=DB::table('order_items')->where('id',$i)->first(); expect($row->original_unit_price)->toBe('10.0000')->and((int)$row->price_overridden_by_user_id)->toBe((int)$u->id); expect((int)DB::table('orders')->where('id',$o)->value('discount_applied_by_user_id'))->toBe((int)$u->id); });

test('table move is tenant location occupancy and audit safe', function(){ [$b,$l,$u,$h]=ooContext();$p=ooProduct($b);$from=ooTable($b,$l,'T1');$to=ooTable($b,$l,'T2');[$o]=ooOrder($b,$l,$u,$p,'open',$from); $this->postJson("/api/v1/orders/$o/move-table",['venue_table_id'=>$to,'reason'=>'Guest requested another table'],$h)->assertOk()->assertJsonPath('data.venue_table_id',$to); $row=DB::table('orders')->where('id',$o)->first(); expect($row->previous_venue_table_id)->toBe($from)->and((int)$row->table_moved_by_user_id)->toBe((int)$u->id); $foreign=Business::query()->create(['name'=>'Foreign','currency'=>'EUR','timezone'=>'UTC','status'=>'active']);$fl=Location::query()->create(['business_id'=>$foreign->id,'name'=>'F','code'=>'F','type'=>'bar','is_active'=>true]);$ft=ooTable($foreign,$fl,'X'); $this->postJson("/api/v1/orders/$o/move-table",['venue_table_id'=>$ft,'reason'=>'Invalid cross tenant move'],$h)->assertNotFound(); });

test('any active payment attempt freezes every commercial mutation and preparation send', function(){
    [$b,$l,$u,$h]=ooContext(); $p=ooProduct($b); [$o,$i]=ooOrder($b,$l,$u,$p,'payment_due'); $p2=ooProduct($b,'Tea','5.0000'); $from=ooTable($b,$l,'T1'); $to=ooTable($b,$l,'T2'); DB::table('orders')->where('id',$o)->update(['type'=>'table','venue_table_id'=>$from]);
    DB::table('payments')->insert(['id'=>(string)Str::ulid(),'business_id'=>$b->id,'order_id'=>$o,'collected_by_user_id'=>$u->id,'method'=>'card','status'=>'pending','amount'=>'1.0000','currency'=>'EUR','amount_base'=>'1.0000','base_currency'=>'EUR','exchange_rate'=>'1.0000000000','idempotency_key'=>'ops-'.Str::uuid(),'created_at'=>now(),'updated_at'=>now()]);
    $this->postJson("/api/v1/orders/$o/items",['product_id'=>$p2,'quantity'=>'1'],$h)->assertStatus(422);
    $this->patchJson("/api/v1/order-items/$i",['quantity'=>'2'],$h)->assertStatus(422);
    $this->deleteJson("/api/v1/order-items/$i",['reason'=>'Too late removal'],$h)->assertStatus(422);
    $this->putJson("/api/v1/orders/$o/discount",['amount'=>'1','reason'=>'Too late discount'],$h)->assertStatus(422);
    $this->putJson("/api/v1/order-items/$i/price",['unit_price'=>'8','reason'=>'Too late override'],$h)->assertStatus(422);
    $this->postJson("/api/v1/orders/$o/move-table",['venue_table_id'=>$to,'reason'=>'Too late move'],$h)->assertStatus(422);
    $this->postJson("/api/v1/orders/$o/send",[],$h)->assertStatus(422);
    expect(DB::table('orders')->where('id',$o)->value('grand_total'))->toBe('10.0000')
        ->and(DB::table('order_items')->where('id',$i)->value('preparation_status'))->toBe('pending')
        ->and(DB::table('order_items')->where('order_id',$o)->count())->toBe(1);
});
