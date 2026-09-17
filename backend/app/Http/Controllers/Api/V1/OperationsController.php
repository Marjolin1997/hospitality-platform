<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class OperationsController extends Controller
{
    public function products(Request $request): JsonResponse
    {
        $business = app(Business::class);
        $rows = DB::table('products')->leftJoin('product_categories','product_categories.id','=','products.product_category_id')
            ->where('products.business_id',$business->id)
            ->select('products.*','product_categories.name as category_name')->orderBy('products.name')->get();
        return response()->json(['data'=>$rows]);
    }

    public function saveProduct(Request $request): JsonResponse
    {
        $business = app(Business::class);
        $data = $request->validate([
            'id'=>['nullable','string'], 'name'=>['required','string','max:255'], 'category_id'=>['nullable','string'],
            'sku'=>['nullable','string','max:64'], 'sale_price'=>['required','numeric','min:0'], 'tax_rate'=>['required','numeric','min:0','max:100'],
            'preparation_station'=>['nullable',Rule::in(['bar','kitchen'])], 'tracks_stock'=>['required','boolean'], 'is_active'=>['required','boolean'],
        ]);
        if (!empty($data['category_id'])) abort_unless(DB::table('product_categories')->where('business_id',$business->id)->where('id',$data['category_id'])->exists(),422,'Invalid category.');
        $payload=['business_id'=>$business->id,'product_category_id'=>$data['category_id']??null,'name'=>$data['name'],'sku'=>$data['sku']??null,'sale_price'=>$data['sale_price'],'tax_rate'=>$data['tax_rate'],'preparation_station'=>$data['preparation_station']??null,'tracks_stock'=>$data['tracks_stock'],'is_active'=>$data['is_active'],'updated_at'=>now()];
        if (!empty($data['id'])) {
            $updated=DB::table('products')->where('business_id',$business->id)->where('id',$data['id'])->update($payload); abort_unless($updated,404);
            $id=$data['id'];
        } else { $id=(string) Str::ulid(); $payload['id']=$id; $payload['created_at']=now(); DB::table('products')->insert($payload); }
        return response()->json(['data'=>DB::table('products')->where('business_id',$business->id)->where('id',$id)->first()]);
    }

    public function inventory(Request $request): JsonResponse
    {
        $business=app(Business::class); $location=$this->location($request,$business);
        $rows=DB::table('products as p')->leftJoin('inventory_stocks as s',fn($j)=>$j->on('s.product_id','=','p.id')->where('s.business_id',$business->id)->where('s.location_id',$location))
            ->where('p.business_id',$business->id)->where('p.tracks_stock',true)->select('p.id','p.name','p.sku',DB::raw('COALESCE(s.quantity_on_hand,0) quantity_on_hand'),DB::raw('COALESCE(s.reorder_level,0) reorder_level'))->orderBy('p.name')->get();
        return response()->json(['data'=>$rows]);
    }

    public function adjustInventory(Request $request): JsonResponse
    {
        $business=app(Business::class); $location=$this->location($request,$business);
        $data=$request->validate(['product_id'=>['required','string'],'quantity_delta'=>['required','numeric','not_in:0'],'note'=>['nullable','string','max:500']]);
        abort_unless(DB::table('products')->where('business_id',$business->id)->where('id',$data['product_id'])->where('tracks_stock',true)->exists(),422,'Invalid stock product.');
        DB::transaction(function() use($business,$location,$data,$request):void {
            $stock=DB::table('inventory_stocks')->where(['business_id'=>$business->id,'location_id'=>$location,'product_id'=>$data['product_id']])->lockForUpdate()->first();
            $next=(string)((float)($stock->quantity_on_hand??0)+(float)$data['quantity_delta']);
            if($stock) DB::table('inventory_stocks')->where('id',$stock->id)->update(['quantity_on_hand'=>$next,'updated_at'=>now()]);
            else DB::table('inventory_stocks')->insert(['id'=>(string)Str::ulid(),'business_id'=>$business->id,'location_id'=>$location,'product_id'=>$data['product_id'],'quantity_on_hand'=>$next,'reorder_level'=>0,'created_at'=>now(),'updated_at'=>now()]);
            DB::table('inventory_movements')->insert(['id'=>(string)Str::ulid(),'business_id'=>$business->id,'location_id'=>$location,'product_id'=>$data['product_id'],'created_by_user_id'=>$request->user()->id,'type'=>'adjustment','quantity_delta'=>$data['quantity_delta'],'note'=>$data['note']??null,'occurred_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
        });
        return response()->json(['message'=>'Inventory adjusted.']);
    }

    public function finance(Request $request): JsonResponse
    {
        $business=app(Business::class); $from=now()->startOfMonth();
        $sales=DB::table('payments')->where('business_id',$business->id)->where('status','completed')->where('paid_at','>=',$from)->sum('amount_base');
        $expenses=DB::table('expenses')->where('business_id',$business->id)->where('status','posted')->where('expense_date','>=',$from->toDateString())->sum('amount');
        $recent=DB::table('expenses')->where('business_id',$business->id)->orderByDesc('expense_date')->limit(50)->get();
        return response()->json(['data'=>['sales'=>(string)$sales,'expenses'=>(string)$expenses,'net'=>(string)((float)$sales-(float)$expenses),'recent_expenses'=>$recent]]);
    }

    public function createExpense(Request $request): JsonResponse
    {
        $business=app(Business::class); $data=$request->validate(['location_id'=>['nullable','string'],'category'=>['required','string','max:80'],'description'=>['required','string','max:255'],'amount'=>['required','numeric','gt:0'],'expense_date'=>['required','date']]);
        if(!empty($data['location_id'])) abort_unless(DB::table('locations')->where('business_id',$business->id)->where('id',$data['location_id'])->exists(),422,'Invalid location.');
        $id=(string)Str::ulid(); DB::table('expenses')->insert(['id'=>$id,'business_id'=>$business->id,'location_id'=>$data['location_id']??null,'created_by_user_id'=>$request->user()->id,'category'=>$data['category'],'description'=>$data['description'],'amount'=>$data['amount'],'currency'=>$business->currency,'expense_date'=>$data['expense_date'],'status'=>'posted','created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['data'=>DB::table('expenses')->where('business_id',$business->id)->where('id',$id)->first()],201);
    }

    public function invoices(Request $request): JsonResponse
    {
        $business=app(Business::class); return response()->json(['data'=>DB::table('invoices')->where('business_id',$business->id)->orderByDesc('created_at')->limit(100)->get()]);
    }

    public function issueInvoice(Request $request): JsonResponse
    {
        $business=app(Business::class); $data=$request->validate(['order_id'=>['required','string'],'customer_name'=>['nullable','string','max:255'],'customer_tax_number'=>['nullable','string','max:80']]);
        $order=DB::table('orders')->where('business_id',$business->id)->where('id',$data['order_id'])->first(); abort_unless($order,404);
        $existing=DB::table('invoices')->where('business_id',$business->id)->where('order_id',$order->id)->where('status','issued')->first(); if($existing)return response()->json(['data'=>$existing]);
        $id=(string)Str::ulid(); $number='INV-'.now($business->timezone)->format('Ymd').'-'.strtoupper(substr($id,-6));
        DB::table('invoices')->insert(['id'=>$id,'business_id'=>$business->id,'location_id'=>$order->location_id,'order_id'=>$order->id,'created_by_user_id'=>$request->user()->id,'number'=>$number,'status'=>'issued','currency'=>$order->currency,'subtotal'=>$order->subtotal,'tax_total'=>$order->tax_total,'grand_total'=>$order->grand_total,'customer_name'=>$data['customer_name']??null,'customer_tax_number'=>$data['customer_tax_number']??null,'issued_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['data'=>DB::table('invoices')->where('id',$id)->first()],201);
    }

    public function staff(): JsonResponse
    {
        $business=app(Business::class); $rows=DB::table('business_user as bu')->join('users as u','u.id','=','bu.user_id')->leftJoin('roles as r','r.id','=','bu.role_id')->where('bu.business_id',$business->id)->select('u.id','u.name','u.email','bu.status','bu.role_id','r.name as role_name')->orderBy('u.name')->get();
        $roles=DB::table('roles')->where('business_id',$business->id)->select('id','name','slug')->orderBy('name')->get(); return response()->json(['data'=>['staff'=>$rows,'roles'=>$roles]]);
    }

    public function updateStaff(Request $request, int $user): JsonResponse
    {
        $business=app(Business::class); $data=$request->validate(['role_id'=>['required','string'],'status'=>['required',Rule::in(['active','inactive'])]]);
        abort_unless(DB::table('roles')->where('business_id',$business->id)->where('id',$data['role_id'])->exists(),422,'Invalid business role.');
        $updated=DB::table('business_user')->where('business_id',$business->id)->where('user_id',$user)->update(['role_id'=>$data['role_id'],'status'=>$data['status'],'updated_at'=>now()]); abort_unless($updated,404);
        return response()->json(['message'=>'Staff membership updated.']);
    }

    public function settings(): JsonResponse
    {
        $business=app(Business::class); $settings=DB::table('business_settings')->where('business_id',$business->id)->pluck('value','key')->map(fn($v)=>json_decode($v,true)); return response()->json(['data'=>['business'=>['name'=>$business->name,'legal_name'=>$business->legal_name,'tax_number'=>$business->tax_number,'currency'=>$business->currency,'timezone'=>$business->timezone],'settings'=>$settings]]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $business=app(Business::class); $data=$request->validate(['receipt_footer'=>['nullable','string','max:500'],'service_charge_enabled'=>['required','boolean'],'low_stock_alerts'=>['required','boolean']]);
        foreach($data as $key=>$value) DB::table('business_settings')->updateOrInsert(['business_id'=>$business->id,'key'=>$key],['id'=>(string)Str::ulid(),'value'=>json_encode($value),'created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['message'=>'Settings saved.']);
    }

    private function location(Request $request, Business $business): string
    {
        $id=(string)$request->query('location_id',$request->input('location_id','')); abort_unless($id,422,'Location is required.');
        abort_unless(DB::table('locations')->where('business_id',$business->id)->where('id',$id)->where('is_active',true)->exists(),422,'Invalid location.'); return $id;
    }
}
