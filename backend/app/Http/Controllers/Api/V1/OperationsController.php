<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SaveProductRequest;
use App\Http\Requests\Api\V1\SetProductStatusRequest;
use App\Http\Requests\Api\V1\StoreExpenseRequest;
use App\Http\Requests\Api\V1\ReverseExpenseRequest;
use App\Models\Business;
use App\Services\Operations\OperationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class OperationsController extends Controller
{
    public function __construct(private readonly OperationsService $operations) {}

    public function products(): JsonResponse
    {
        $business = app(Business::class);
        $rows = DB::table('products')->leftJoin('product_categories','product_categories.id','=','products.product_category_id')
            ->where('products.business_id',$business->id)
            ->select('products.*','product_categories.name as category_name')->orderBy('products.name')->get();
        $categories = DB::table('product_categories')->where('business_id',$business->id)->where('is_active',true)
            ->select('id','name')->orderBy('sort_order')->orderBy('name')->get();
        return response()->json(['data'=>['products'=>$rows,'categories'=>$categories]]);
    }

    public function saveProduct(SaveProductRequest $request): JsonResponse
    {
        $product = $this->operations->saveProduct(app(Business::class), $request->validated());
        return response()->json(['data'=>$product], $request->filled('id') ? 200 : 201);
    }

    public function setProductStatus(SetProductStatusRequest $request, string $product): JsonResponse
    {
        return response()->json(['data'=>$this->operations->setProductStatus(app(Business::class), $product, (bool)$request->validated('is_active'))]);
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
        $this->operations->adjustInventory($business, $location, $data, $request->user()->id);
        return response()->json(['message'=>'Inventory adjusted.']);
    }

    public function reverseExpense(ReverseExpenseRequest $request, string $expense): JsonResponse
    {
        $reversal = $this->operations->reverseExpense(app(Business::class), $expense, $request->validated('reason'), $request->user()->id);
        return response()->json(['data' => $reversal], 201);
    }

    public function finance(): JsonResponse
    {
        return response()->json(['data'=>$this->operations->financeOverview(app(Business::class))]);
    }

    public function createExpense(StoreExpenseRequest $request): JsonResponse
    {
        $expense = $this->operations->createExpense(app(Business::class), $request->validated(), $request->user()->id);
        return response()->json(['data' => $expense], 201);
    }

    public function invoices(): JsonResponse
    {
        $business=app(Business::class); return response()->json(['data'=>DB::table('invoices')->where('business_id',$business->id)->orderByDesc('created_at')->limit(100)->get()]);
    }

    public function issueInvoice(Request $request): JsonResponse
    {
        $business=app(Business::class); $data=$request->validate(['order_id'=>['required','string'],'customer_name'=>['nullable','string','max:255'],'customer_tax_number'=>['nullable','string','max:80']]);
        $invoice=$this->operations->issueInvoice($business,$data,$request->user()->id);
        return response()->json(['data'=>$invoice]);
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
        $membership=DB::table('business_user')->where('business_id',$business->id)->where('user_id',$user);
        abort_unless($membership->exists(),404);
        $membership->update(['role_id'=>$data['role_id'],'status'=>$data['status'],'updated_at'=>now()]);
        return response()->json(['message'=>'Staff membership updated.']);
    }

    public function settings(): JsonResponse
    {
        $business=app(Business::class); $settings=DB::table('business_settings')->where('business_id',$business->id)->pluck('value','key')->map(fn($v)=>json_decode($v,true)); return response()->json(['data'=>['business'=>['name'=>$business->name,'legal_name'=>$business->legal_name,'tax_number'=>$business->tax_number,'currency'=>$business->currency,'timezone'=>$business->timezone],'settings'=>$settings]]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $business=app(Business::class); $data=$request->validate(['receipt_footer'=>['nullable','string','max:500'],'service_charge_enabled'=>['required','boolean'],'low_stock_alerts'=>['required','boolean']]);
        $this->operations->updateSettings($business,$data);
        return response()->json(['message'=>'Settings saved.']);
    }

    private function location(Request $request, Business $business): string
    {
        $id=(string)$request->query('location_id',$request->input('location_id','')); abort_unless($id,422,'Location is required.');
        abort_unless(DB::table('locations')->where('business_id',$business->id)->where('id',$id)->where('is_active',true)->exists(),422,'Invalid location.'); return $id;
    }
}
