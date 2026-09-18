<?php

use App\Models\Business;
use App\Models\Location;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Brick\Math\BigDecimal;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {$this->seed(PermissionSeeder::class);});
function omtBusiness(string $name): Business{return Business::query()->create(['name'=>$name,'currency'=>'EUR','timezone'=>'Europe/Berlin','status'=>'active']);}
function omtLocation(Business $business,string $code): Location{return Location::query()->create(['business_id'=>$business->id,'name'=>$code,'code'=>$code,'type'=>'bar','is_active'=>true]);}
function omtUser(Business $business): User{$user=User::query()->create(['name'=>'Operations Owner','email'=>Str::lower(Str::random(10)).'@example.test','password'=>bcrypt('password')]);$role=Role::query()->create(['business_id'=>$business->id,'name'=>'Owner','slug'=>'owner-'.Str::lower(Str::random(6)),'is_system'=>false]);$role->permissions()->sync(Permission::query()->pluck('id'));$user->businesses()->attach($business->id,['role_id'=>$role->id,'status'=>'active']);return $user;}
function omtHeaders(User $user,Business $business): array{Sanctum::actingAs($user);return ['X-Business-Id'=>$business->id];}
function omtProduct(Business $business,bool $tracksStock=true): string{$id=(string)Str::ulid();DB::table('products')->insert(['id'=>$id,'business_id'=>$business->id,'name'=>'Product '.Str::random(5),'sale_price'=>'5.0000','tax_rate'=>'0.0000','is_active'=>true,'tracks_stock'=>$tracksStock,'created_at'=>now(),'updated_at'=>now()]);return $id;}
function omtOrder(Business $business,Location $location,User $user,string $status='paid'): string{$id=(string)Str::ulid();DB::table('orders')->insert(['id'=>$id,'business_id'=>$business->id,'location_id'=>$location->id,'opened_by_user_id'=>$user->id,'number'=>'ORD-'.Str::random(8),'type'=>'takeaway','status'=>$status,'currency'=>'EUR','subtotal'=>'10.0000','discount_total'=>'0.0000','tax_total'=>'2.0000','grand_total'=>'12.0000','opened_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);return $id;}
function omtOrderItem(Business $business,string $orderId,string $name='Invoice item'): string{$id=(string)Str::ulid();DB::table('order_items')->insert(['id'=>$id,'business_id'=>$business->id,'order_id'=>$orderId,'product_name_snapshot'=>$name,'sku_snapshot'=>'INV-SKU','quantity'=>'1.0000','unit_price'=>'10.0000','tax_rate'=>'20.0000','line_subtotal'=>'10.0000','line_tax'=>'2.0000','line_total'=>'12.0000','preparation_status'=>'served','created_at'=>now(),'updated_at'=>now()]);return $id;}
function omtSettleOrder(Business $business,string $orderId,User $user,string $amount='12.0000'): string{$id=(string)Str::ulid();DB::table('payments')->insert(['id'=>$id,'business_id'=>$business->id,'order_id'=>$orderId,'collected_by_user_id'=>$user->id,'method'=>'card','status'=>'completed','amount'=>$amount,'amount_base'=>$amount,'currency'=>'EUR','base_currency'=>'EUR','exchange_rate'=>'1.0000000000','idempotency_key'=>'invoice-pay-'.Str::uuid(),'paid_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);return $id;}

test('inventory adjustment is tenant and stock tracking safe',function():void{$a=omtBusiness('A');$b=omtBusiness('B');$la=omtLocation($a,'A1');$lb=omtLocation($b,'B1');$user=omtUser($a);$headers=omtHeaders($user,$a);$productA=omtProduct($a,true);$productB=omtProduct($b,true);$nonStock=omtProduct($a,false);$this->postJson('/api/v1/inventory/adjustments',['location_id'=>$la->id,'product_id'=>$productA,'quantity_delta'=>'2.1250','note'=>'Opening stock'],$headers)->assertOk();expect((string)DB::table('inventory_stocks')->where('business_id',$a->id)->where('product_id',$productA)->value('quantity_on_hand'))->toBe('2.1250');expect(DB::table('inventory_movements')->where('business_id',$a->id)->where('product_id',$productA)->count())->toBe(1);$this->postJson('/api/v1/inventory/adjustments',['location_id'=>$lb->id,'product_id'=>$productA,'quantity_delta'=>1],$headers)->assertStatus(422);$this->postJson('/api/v1/inventory/adjustments',['location_id'=>$la->id,'product_id'=>$productB,'quantity_delta'=>1],$headers)->assertStatus(422);$this->postJson('/api/v1/inventory/adjustments',['location_id'=>$la->id,'product_id'=>$nonStock,'quantity_delta'=>1],$headers)->assertStatus(422);});

test('product management cannot attach a category from another business',function():void{$a=omtBusiness('A');$b=omtBusiness('B');$user=omtUser($a);$headers=omtHeaders($user,$a);$category=(string)Str::ulid();DB::table('product_categories')->insert(['id'=>$category,'business_id'=>$b->id,'name'=>'Foreign','sort_order'=>0,'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);$this->postJson('/api/v1/management/products',['name'=>'Coffee','category_id'=>$category,'sale_price'=>'3.50','tax_rate'=>'20','tracks_stock'=>false,'is_active'=>true],$headers)->assertStatus(422);expect(DB::table('products')->where('business_id',$a->id)->where('name','Coffee')->exists())->toBeFalse();});

test('product status mutation preserves catalog data and is tenant safe',function():void{$a=omtBusiness('A');$b=omtBusiness('B');$user=omtUser($a);$headers=omtHeaders($user,$a);$category=(string)Str::ulid();DB::table('product_categories')->insert(['id'=>$category,'business_id'=>$a->id,'name'=>'Coffee','sort_order'=>0,'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);$id=(string)Str::ulid();DB::table('products')->insert(['id'=>$id,'business_id'=>$a->id,'product_category_id'=>$category,'name'=>'Espresso','sku'=>'ESP-1','sale_price'=>'3.5000','tax_rate'=>'19.0000','preparation_station'=>'bar','tracks_stock'=>true,'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);$this->patchJson('/api/v1/management/products/'.$id.'/status',['is_active'=>false],$headers)->assertOk()->assertJsonPath('data.is_active',false);$row=DB::table('products')->where('id',$id)->first();expect($row->product_category_id)->toBe($category)->and($row->sku)->toBe('ESP-1')->and($row->preparation_station)->toBe('bar')->and((bool)$row->tracks_stock)->toBeTrue();$foreign=omtProduct($b);$this->patchJson('/api/v1/management/products/'.$foreign.'/status',['is_active'=>false],$headers)->assertNotFound();});

test('product sku is unique inside a business but reusable across businesses',function():void{$a=omtBusiness('A');$b=omtBusiness('B');$userA=omtUser($a);$userB=omtUser($b);$payload=['name'=>'Espresso','sku'=>'ESP-001','sale_price'=>'3.50','tax_rate'=>'19','tracks_stock'=>false,'is_active'=>true];$this->postJson('/api/v1/management/products',$payload,omtHeaders($userA,$a))->assertCreated();$this->postJson('/api/v1/management/products',[...$payload,'name'=>'Double Espresso'],omtHeaders($userA,$a))->assertStatus(422)->assertJsonValidationErrors('sku');$this->postJson('/api/v1/management/products',$payload,omtHeaders($userB,$b))->assertCreated();});

test('finance overview subtracts completed refunds and posted expenses',function():void{$business=omtBusiness('Finance');$location=omtLocation($business,'F1');$user=omtUser($business);$headers=omtHeaders($user,$business);$order=omtOrder($business,$location,$user);$payment=(string)Str::ulid();DB::table('payments')->insert(['id'=>$payment,'business_id'=>$business->id,'order_id'=>$order,'collected_by_user_id'=>$user->id,'method'=>'card','status'=>'completed','currency'=>'EUR','base_currency'=>'EUR','amount'=>'100.0000','amount_base'=>'100.0000','exchange_rate'=>'1.0000000000','idempotency_key'=>'p-'.Str::uuid(),'paid_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);DB::table('payment_refunds')->insert(['id'=>(string)Str::ulid(),'business_id'=>$business->id,'payment_id'=>$payment,'refunded_by_user_id'=>$user->id,'status'=>'completed','currency'=>'EUR','base_currency'=>'EUR','amount'=>'15.0000','amount_base'=>'15.0000','exchange_rate'=>'1.0000000000','reason'=>'Test refund','idempotency_key'=>'r-'.Str::uuid(),'refunded_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);DB::table('expenses')->insert(['id'=>(string)Str::ulid(),'business_id'=>$business->id,'created_by_user_id'=>$user->id,'category'=>'supplies','description'=>'Coffee beans','amount'=>'20.0000','currency'=>'EUR','expense_date'=>now()->toDateString(),'status'=>'posted','created_at'=>now(),'updated_at'=>now()]);$this->getJson('/api/v1/finance/overview',$headers)->assertOk()->assertJsonPath('data.gross_sales','100.0000')->assertJsonPath('data.refunds','15.0000')->assertJsonPath('data.sales','85.0000')->assertJsonPath('data.expenses','20.0000')->assertJsonPath('data.net','65.0000');});

test('finance overview reports open shifts and closed drawer variance',function():void{
    $business=omtBusiness('Cash reconciliation');$location=omtLocation($business,'CR1');$user=omtUser($business);$headers=omtHeaders($user,$business);
    $register=(string)Str::ulid();
    DB::table('cash_registers')->insert(['id'=>$register,'business_id'=>$business->id,'location_id'=>$location->id,'name'=>'Main','code'=>'CR-MAIN','is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);
    foreach ([['closed','5.0000',now()->subDays(2)],['closed','-3.0000',now()->subDay()],['open',null,null]] as [$status,$difference,$closedAt]) {
        DB::table('cash_sessions')->insert([
            'id'=>(string)Str::ulid(),'business_id'=>$business->id,'location_id'=>$location->id,'cash_register_id'=>$register,
            'opened_by_user_id'=>$user->id,'closed_by_user_id'=>$status==='closed'?$user->id:null,'base_currency'=>'EUR','opening_cash'=>'100.0000',
            'expected_cash'=>$status==='closed'?'100.0000':null,'counted_cash'=>$status==='closed'?(BigDecimal::of('100.0000')->plus($difference)->toScale(4)) : null,
            'cash_difference'=>$difference,'status'=>$status,'opened_at'=>now()->subDays(3),'closed_at'=>$closedAt,'created_at'=>now(),'updated_at'=>now(),
        ]);
    }
    $this->getJson('/api/v1/finance/overview',$headers)->assertOk()
        ->assertJsonPath('data.cash_reconciliation.open_sessions',1)
        ->assertJsonPath('data.cash_reconciliation.closed_sessions',2)
        ->assertJsonPath('data.cash_reconciliation.cash_over','5.0000')
        ->assertJsonPath('data.cash_reconciliation.cash_short','3.0000')
        ->assertJsonPath('data.cash_reconciliation.net_variance','2.0000');
});

test('expense posting is tenant validated precise and rejects future dates',function():void{
    $a=omtBusiness('Expense A');$b=omtBusiness('Expense B');$la=omtLocation($a,'EA1');$lb=omtLocation($b,'EB1');$user=omtUser($a);$headers=omtHeaders($user,$a);
    $this->postJson('/api/v1/expenses',['location_id'=>$lb->id,'category'=>'Supplies','description'=>'Foreign location','amount'=>'12.3456','expense_date'=>now()->toDateString()],$headers)->assertStatus(422)->assertJsonValidationErrors('location_id');
    $this->postJson('/api/v1/expenses',['location_id'=>$la->id,'category'=>'Supplies','description'=>'Future expense','amount'=>'12.3456','expense_date'=>now()->addDay()->toDateString()],$headers)->assertStatus(422)->assertJsonValidationErrors('expense_date');
    $this->postJson('/api/v1/expenses',['location_id'=>$la->id,'category'=>' Supplies ','description'=>' Coffee beans ','amount'=>'12.3456','expense_date'=>now()->toDateString()],$headers)->assertCreated()->assertJsonPath('data.amount','12.3456')->assertJsonPath('data.currency','EUR');
    expect(DB::table('expenses')->where('business_id',$a->id)->count())->toBe(1)->and(DB::table('expenses')->where('business_id',$a->id)->value('category'))->toBe('Supplies');
});

test('expense reversal preserves history and neutralizes finance totals exactly once',function():void{
    $business=omtBusiness('Expense reversal');$location=omtLocation($business,'ER1');$user=omtUser($business);$headers=omtHeaders($user,$business);
    $expense=$this->postJson('/api/v1/expenses',['location_id'=>$location->id,'category'=>'Supplies','description'=>'Coffee beans','amount'=>'25.5000','expense_date'=>now()->toDateString()],$headers)->assertCreated()->json('data.id');
    $this->getJson('/api/v1/finance/overview',$headers)->assertOk()->assertJsonPath('data.expenses','25.5000');
    $this->postJson("/api/v1/expenses/{$expense}/reverse",['reason'=>'Duplicate supplier receipt'],$headers)->assertCreated()->assertJsonPath('data.status','reversal')->assertJsonPath('data.reversal_of_expense_id',$expense);
    expect(DB::table('expenses')->where('id',$expense)->value('status'))->toBe('reversed')->and(DB::table('expenses')->where('reversal_of_expense_id',$expense)->count())->toBe(1);
    $this->getJson('/api/v1/finance/overview',$headers)->assertOk()->assertJsonPath('data.expenses','0.0000');
    $this->postJson("/api/v1/expenses/{$expense}/reverse",['reason'=>'Second reversal attempt'],$headers)->assertStatus(422)->assertJsonValidationErrors('expense');
});

test('expense creators cannot reverse without approval permission',function():void{
    $business=omtBusiness('Expense RBAC');$location=omtLocation($business,'ERBAC');$user=omtUser($business);$headers=omtHeaders($user,$business);
    $roleId=DB::table('business_user')->where('business_id',$business->id)->where('user_id',$user->id)->value('role_id');
    $approveId=Permission::query()->where('key','expenses.approve')->value('id');
    DB::table('permission_role')->where('role_id',$roleId)->where('permission_id',$approveId)->delete();

    $expense=$this->postJson('/api/v1/expenses',[
        'location_id'=>$location->id,'category'=>'Supplies','description'=>'Paper goods','amount'=>'8.5000','expense_date'=>now()->toDateString(),
    ],$headers)->assertCreated()->json('data.id');

    $this->postJson("/api/v1/expenses/{$expense}/reverse",['reason'=>'Unauthorized correction'],$headers)->assertForbidden();
    expect(DB::table('expenses')->where('id',$expense)->value('status'))->toBe('posted')
        ->and(DB::table('expenses')->where('reversal_of_expense_id',$expense)->count())->toBe(0);
});

test('invoice issuance is paid-only sequential immutable and replay safe',function():void{
    $business=omtBusiness('Invoices');$business->update(['legal_name'=>'Invoices GmbH','tax_number'=>'DE-INV-1']);$location=omtLocation($business,'I1');$user=omtUser($business);$headers=omtHeaders($user,$business);
    $order=omtOrder($business,$location,$user,'paid');omtOrderItem($business,$order,'Espresso');omtSettleOrder($business,$order,$user);
    $first=$this->postJson('/api/v1/invoices',['order_id'=>$order,'customer_name'=>'Customer','customer_tax_number'=>'CUST-1'],$headers)->assertCreated();
    $first->assertJsonPath('data.number','INV-'.now($business->timezone)->format('Ymd').'-0001')->assertJsonPath('data.grand_total','12.0000')->assertJsonPath('data.currency','EUR')->assertJsonPath('data.status','issued')->assertJsonPath('data.order_number_snapshot',DB::table('orders')->where('id',$order)->value('number'))->assertJsonCount(1,'data.lines');
    expect(DB::table('invoice_lines')->where('invoice_id',$first->json('data.id'))->value('product_name_snapshot'))->toBe('Espresso');

    $replay=$this->postJson('/api/v1/invoices',['order_id'=>$order,'customer_name'=>'Customer','customer_tax_number'=>'CUST-1'],$headers)->assertCreated();
    expect($replay->json('data.id'))->toBe($first->json('data.id'));
    $this->postJson('/api/v1/invoices',['order_id'=>$order,'customer_name'=>'Changed'],$headers)->assertStatus(422)->assertJsonValidationErrors('order_id');

    $secondOrder=omtOrder($business,$location,$user,'paid');omtOrderItem($business,$secondOrder,'Cappuccino');omtSettleOrder($business,$secondOrder,$user);
    $this->postJson('/api/v1/invoices',['order_id'=>$secondOrder],$headers)->assertCreated()->assertJsonPath('data.number','INV-'.now($business->timezone)->format('Ymd').'-0002');
});

test('invoice issuance rejects foreign unpaid cancelled and unsettled orders',function():void{
    $a=omtBusiness('A');$b=omtBusiness('B');$la=omtLocation($a,'A1');$lb=omtLocation($b,'B1');$userA=omtUser($a);$userB=omtUser($b);$headers=omtHeaders($userA,$a);
    $foreign=omtOrder($b,$lb,$userB,'paid');omtOrderItem($b,$foreign);omtSettleOrder($b,$foreign,$userB);
    $cancelled=omtOrder($a,$la,$userA,'cancelled');omtOrderItem($a,$cancelled);
    $open=omtOrder($a,$la,$userA,'open');omtOrderItem($a,$open);
    $fakePaid=omtOrder($a,$la,$userA,'paid');omtOrderItem($a,$fakePaid);
    $this->postJson('/api/v1/invoices',['order_id'=>$foreign],$headers)->assertNotFound();
    $this->postJson('/api/v1/invoices',['order_id'=>$cancelled],$headers)->assertStatus(422)->assertJsonValidationErrors('order_id');
    $this->postJson('/api/v1/invoices',['order_id'=>$open],$headers)->assertStatus(422)->assertJsonValidationErrors('order_id');
    $this->postJson('/api/v1/invoices',['order_id'=>$fakePaid],$headers)->assertStatus(422)->assertJsonValidationErrors('order_id');
});

test('issued invoice blocks direct refunds until a document correction exists',function():void{
    $business=omtBusiness('Invoice refund guard');$location=omtLocation($business,'IRG');$user=omtUser($business);$headers=omtHeaders($user,$business);
    $order=omtOrder($business,$location,$user,'paid');omtOrderItem($business,$order);$payment=omtSettleOrder($business,$order,$user);
    $this->postJson('/api/v1/invoices',['order_id'=>$order],$headers)->assertCreated();
    $this->postJson("/api/v1/payments/{$payment}/refunds",['location_id'=>$location->id,'amount'=>'1.0000','reason'=>'Customer refund','idempotency_key'=>'invoice-refund-'.Str::uuid()],$headers)->assertStatus(422)->assertJsonValidationErrors('payment');
    expect(DB::table('payment_refunds')->where('payment_id',$payment)->count())->toBe(0);
});

test('full invoice credit note is idempotent immutable and authorizes the refund lifecycle',function():void{
    $business=omtBusiness('Credit notes');$location=omtLocation($business,'CN1');$user=omtUser($business);$headers=omtHeaders($user,$business);
    $order=omtOrder($business,$location,$user,'paid');omtOrderItem($business,$order,'Flat White');$payment=omtSettleOrder($business,$order,$user);
    $invoice=$this->postJson('/api/v1/invoices',['order_id'=>$order,'customer_name'=>'Guest'],$headers)->assertCreated()->json('data');

    $key='credit-'.Str::uuid();
    $first=$this->postJson("/api/v1/invoices/{$invoice['id']}/credit-notes",['reason'=>'Full order return','idempotency_key'=>$key],$headers)
        ->assertCreated()->assertJsonPath('data.number','CN-'.now($business->timezone)->format('Ymd').'-0001')
        ->assertJsonPath('data.status','issued')->assertJsonPath('data.grand_total','12.0000')->assertJsonCount(1,'data.lines');
    $creditId=$first->json('data.id');
    expect(DB::table('invoice_credit_note_lines')->where('invoice_credit_note_id',$creditId)->value('product_name_snapshot'))->toBe('Flat White');

    $replay=$this->postJson("/api/v1/invoices/{$invoice['id']}/credit-notes",['reason'=>'Full order return','idempotency_key'=>$key],$headers)->assertCreated();
    expect($replay->json('data.id'))->toBe($creditId);
    $this->postJson("/api/v1/invoices/{$invoice['id']}/credit-notes",['reason'=>'Different replay','idempotency_key'=>$key],$headers)->assertStatus(422)->assertJsonValidationErrors('idempotency_key');
    $this->postJson("/api/v1/invoices/{$invoice['id']}/credit-notes",['reason'=>'Second correction','idempotency_key'=>'credit-'.Str::uuid()],$headers)->assertStatus(422)->assertJsonValidationErrors('invoice');

    $this->postJson("/api/v1/payments/{$payment}/refunds",[
        'location_id'=>$location->id,'invoice_credit_note_id'=>$creditId,'amount'=>'5.0000','reason'=>'First refund tranche','idempotency_key'=>'refund-'.Str::uuid(),
    ],$headers)->assertCreated()->assertJsonPath('data.invoice_credit_note_id',$creditId);
    expect(DB::table('invoice_credit_notes')->where('id',$creditId)->value('status'))->toBe('partially_refunded')
        ->and(DB::table('orders')->where('id',$order)->value('status'))->toBe('partially_refunded');

    $this->postJson("/api/v1/payments/{$payment}/refunds",[
        'location_id'=>$location->id,'invoice_credit_note_id'=>$creditId,'amount'=>'7.0000','reason'=>'Final refund tranche','idempotency_key'=>'refund-'.Str::uuid(),
    ],$headers)->assertCreated();
    expect(DB::table('invoice_credit_notes')->where('id',$creditId)->value('status'))->toBe('refunded')
        ->and(DB::table('orders')->where('id',$order)->value('status'))->toBe('refunded')
        ->and((string)DB::table('payment_refunds')->where('invoice_credit_note_id',$creditId)->sum('amount_base'))->toBe('12.0000');

    $this->postJson("/api/v1/orders/{$order}/cancel",['reason'=>'Do not erase invoiced history'],$headers)
        ->assertStatus(422)->assertJsonValidationErrors('order');
});

test('invoice correction requires dedicated correction permission',function():void{
    $business=omtBusiness('Credit RBAC');$location=omtLocation($business,'CRBAC');$user=omtUser($business);$headers=omtHeaders($user,$business);
    $order=omtOrder($business,$location,$user,'paid');omtOrderItem($business,$order);omtSettleOrder($business,$order,$user);
    $invoice=$this->postJson('/api/v1/invoices',['order_id'=>$order],$headers)->assertCreated()->json('data.id');

    $roleId=DB::table('business_user')->where('business_id',$business->id)->where('user_id',$user->id)->value('role_id');
    $permissionId=Permission::query()->where('key','invoices.correct')->value('id');
    DB::table('permission_role')->where('role_id',$roleId)->where('permission_id',$permissionId)->delete();

    $this->postJson("/api/v1/invoices/{$invoice}/credit-notes",['reason'=>'Should be forbidden','idempotency_key'=>'credit-'.Str::uuid()],$headers)->assertForbidden();
    expect(DB::table('invoice_credit_notes')->where('invoice_id',$invoice)->count())->toBe(0);
});

test('invoice credit notes remain tenant isolated',function():void{
    $a=omtBusiness('Credit A');$b=omtBusiness('Credit B');$la=omtLocation($a,'CA1');$lb=omtLocation($b,'CB1');$userA=omtUser($a);$userB=omtUser($b);
    $orderB=omtOrder($b,$lb,$userB,'paid');omtOrderItem($b,$orderB);omtSettleOrder($b,$orderB,$userB);
    $invoiceB=$this->postJson('/api/v1/invoices',['order_id'=>$orderB],omtHeaders($userB,$b))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/invoices/{$invoiceB}/credit-notes",['reason'=>'Cross tenant attempt','idempotency_key'=>'credit-'.Str::uuid()],omtHeaders($userA,$a))->assertNotFound();
    expect(DB::table('invoice_credit_notes')->where('business_id',$a->id)->count())->toBe(0);
});

test('staff update rejects roles and memberships from another business',function():void{$a=omtBusiness('A');$b=omtBusiness('B');$owner=omtUser($a);$foreignUser=omtUser($b);$headers=omtHeaders($owner,$a);$foreignRole=DB::table('business_user')->where('business_id',$b->id)->where('user_id',$foreignUser->id)->value('role_id');$this->patchJson('/api/v1/staff/'.$owner->id,['role_id'=>$foreignRole,'status'=>'active'],$headers)->assertStatus(422);$this->patchJson('/api/v1/staff/'.$foreignUser->id,['role_id'=>DB::table('business_user')->where('business_id',$a->id)->where('user_id',$owner->id)->value('role_id'),'status'=>'active'],$headers)->assertNotFound();});

test('settings remain isolated by business',function():void{$a=omtBusiness('A');$b=omtBusiness('B');$userA=omtUser($a);$userB=omtUser($b);$this->putJson('/api/v1/settings',['receipt_footer'=>'A footer','service_charge_enabled'=>true,'low_stock_alerts'=>true],omtHeaders($userA,$a))->assertOk();$this->putJson('/api/v1/settings',['receipt_footer'=>'B footer','service_charge_enabled'=>false,'low_stock_alerts'=>false],omtHeaders($userB,$b))->assertOk();$this->getJson('/api/v1/settings',omtHeaders($userA,$a))->assertOk()->assertJsonPath('data.settings.receipt_footer','A footer')->assertJsonPath('data.settings.service_charge_enabled',true);});
