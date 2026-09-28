<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SaveProductCategoryRequest;
use App\Http\Requests\Api\V1\SaveProductRequest;
use App\Http\Requests\Api\V1\SetProductCategoryStatusRequest;
use App\Http\Requests\Api\V1\SetProductStatusRequest;
use App\Http\Requests\Api\V1\StoreExpenseRequest;
use App\Http\Requests\Api\V1\UpdateBusinessProfileRequest;
use App\Http\Requests\Api\V1\ReverseExpenseRequest;
use App\Models\Business;
use App\Services\Authorization\RoleDelegationPolicy;
use App\Services\Authorization\UpdateBusinessMembership;
use App\Services\Operations\OperationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class OperationsController extends Controller
{
    public function __construct(
        private readonly OperationsService $operations,
        private readonly RoleDelegationPolicy $delegation,
    ) {}

    public function products(): JsonResponse
    {
        $business = app(Business::class);
        $rows = DB::table('products')->leftJoin('product_categories','product_categories.id','=','products.product_category_id')
            ->where('products.business_id',$business->id)
            ->select('products.*','product_categories.name as category_name')->orderBy('products.name')->get();
        $categories = DB::table('product_categories')->where('business_id',$business->id)->where('is_active',true)
            ->select('id','name')->orderBy('sort_order')->orderBy('name')->get();
        $stations = DB::table('preparation_stations')->where('business_id',$business->id)
            ->select('id','name','code','is_active')->orderByDesc('is_active')->orderBy('sort_order')->orderBy('name')->get()
            ->map(function (object $station): object {
                $station->is_active = (bool) $station->is_active;
                return $station;
            });
        return response()->json(['data'=>['products'=>$rows,'categories'=>$categories,'stations'=>$stations]]);
    }

    public function categories(): JsonResponse
    {
        $business = app(Business::class);

        $rows = DB::table('product_categories')
            ->where('business_id', $business->id)
            ->select('id', 'name', 'color', 'sort_order', 'is_active')
            ->selectSub(function ($query): void {
                $query->from('products')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('products.product_category_id', 'product_categories.id')
                    ->whereColumn('products.business_id', 'product_categories.business_id');
            }, 'product_count')
            ->selectSub(function ($query): void {
                $query->from('products')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('products.product_category_id', 'product_categories.id')
                    ->whereColumn('products.business_id', 'product_categories.business_id')
                    ->where('products.is_active', true);
            }, 'active_product_count')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(function (object $row): object {
                $row->is_active = (bool) $row->is_active;
                $row->product_count = (int) $row->product_count;
                $row->active_product_count = (int) $row->active_product_count;

                return $row;
            });

        return response()->json(['data' => $rows]);
    }

    public function saveCategory(SaveProductCategoryRequest $request): JsonResponse
    {
        $category = $this->operations->saveCategory(app(Business::class), $request->validated(), (int) $request->user()->id);

        return response()->json(
            ['data' => $category],
            $request->filled('id') ? 200 : 201,
        );
    }

    public function setCategoryStatus(SetProductCategoryStatusRequest $request, string $category): JsonResponse
    {
        return response()->json([
            'data' => $this->operations->setCategoryStatus(
                app(Business::class),
                $category,
                (bool) $request->validated('is_active'),
                (int) $request->user()->id,
            ),
        ]);
    }

    public function categoryEvents(string $category): JsonResponse
    {
        return response()->json([
            'data' => $this->configurationEvents('product_category', $category, 'product_categories'),
        ]);
    }

    public function productEvents(string $product): JsonResponse
    {
        return response()->json([
            'data' => $this->configurationEvents('product', $product, 'products'),
        ]);
    }

    public function saveProduct(SaveProductRequest $request): JsonResponse
    {
        $product = $this->operations->saveProduct(app(Business::class), $request->validated(), (int) $request->user()->id);
        return response()->json(['data'=>$product], $request->filled('id') ? 200 : 201);
    }

    public function setProductStatus(SetProductStatusRequest $request, string $product): JsonResponse
    {
        return response()->json(['data'=>$this->operations->setProductStatus(app(Business::class), $product, (bool)$request->validated('is_active'), (int) $request->user()->id)]);
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
        $data=$request->validate(['idempotency_key'=>['required','string','min:16','max:64'],'product_id'=>['required','string'],'quantity_delta'=>['required','numeric','not_in:0'],'note'=>['nullable','string','max:500']]);
        $this->operations->adjustInventory($business, $location, $data, $request->user()->id);
        return response()->json(['message'=>'Inventory adjusted.']);
    }

    public function reverseExpense(ReverseExpenseRequest $request, string $expense): JsonResponse
    {
        $reversal = $this->operations->reverseExpense(
            app(Business::class),
            $expense,
            $request->validated('reason'),
            $request->validated('idempotency_key'),
            (int) $request->user()->id,
        );
        return response()->json(['data' => $reversal], 201);
    }

    public function finance(): JsonResponse
    {
        return response()->json(['data'=>$this->operations->financeOverview(app(Business::class))]);
    }

    public function expenses(): JsonResponse
    {
        $business = app(Business::class);

        return response()->json([
            'data' => DB::table('expenses')
                ->where('business_id', $business->getKey())
                ->orderByDesc('expense_date')
                ->orderByDesc('created_at')
                ->limit(100)
                ->get(),
        ]);
    }

    public function createExpense(StoreExpenseRequest $request): JsonResponse
    {
        $expense = $this->operations->createExpense(app(Business::class), $request->validated(), $request->user()->id);
        return response()->json(['data' => $expense], 201);
    }

    public function staff(Request $request): JsonResponse
    {
        $business = app(Business::class);
        $rows = DB::table('business_user as bu')
            ->join('users as u', 'u.id', '=', 'bu.user_id')
            ->leftJoin('roles as r', function ($join) use ($business): void {
                $join->on('r.id', '=', 'bu.role_id')
                    ->where('r.business_id', $business->id);
            })
            ->where('bu.business_id', $business->id)
            ->select('u.id', 'u.name', 'u.email', 'bu.status', 'bu.role_id', 'r.name as role_name')
            ->orderBy('u.name')
            ->get();

        $roles = DB::table('roles')
            ->where('business_id', $business->id)
            ->select('id', 'name', 'slug')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => [
                'staff' => $rows,
                'roles' => $roles,
                'assignable_role_ids' => $this->delegation->assignableRoleIds(
                    $business,
                    (int) $request->user()->id,
                ),
            ],
        ]);
    }

    public function staffEvents(int $user): JsonResponse
    {
        $business = app(Business::class);

        $member = DB::table('business_user as bu')
            ->join('users as u', 'u.id', '=', 'bu.user_id')
            ->where('bu.business_id', $business->getKey())
            ->where('bu.user_id', $user)
            ->first(['u.id', 'u.name', 'u.email']);

        abort_unless($member, 404);

        $events = DB::table('business_membership_audits as bma')
            ->join('users as actor', 'actor.id', '=', 'bma.performed_by_user_id')
            ->where('bma.business_id', $business->getKey())
            ->where('bma.target_user_id', $user)
            ->orderByDesc('bma.performed_at')
            ->orderByDesc('bma.id')
            ->limit(100)
            ->get([
                'bma.id',
                'bma.action',
                'bma.previous_role_name',
                'bma.previous_role_slug',
                'bma.previous_status',
                'bma.new_role_name',
                'bma.new_role_slug',
                'bma.new_status',
                'bma.performed_at',
                'actor.id as performed_by_user_id',
                'actor.name as performed_by_name',
            ]);

        return response()->json([
            'data' => [
                'member' => $member,
                'events' => $events,
            ],
        ]);
    }

    public function updateStaff(Request $request, int $user, UpdateBusinessMembership $update): JsonResponse
    {
        $data = $request->validate([
            'role_id' => ['required','string'],
            'status' => ['required',Rule::in(['active','inactive'])],
        ]);

        $update->execute(
            app(Business::class),
            $user,
            $data['role_id'],
            $data['status'],
            (int) $request->user()->id,
        );

        return response()->json(['message'=>'Staff membership updated.']);
    }

    public function settings(): JsonResponse
    {
        $business=app(Business::class); $settings=DB::table('business_settings')->where('business_id',$business->id)->pluck('value','key')->map(fn($v)=>json_decode($v,true)); return response()->json(['data'=>['business'=>['name'=>$business->name,'legal_name'=>$business->legal_name,'tax_number'=>$business->tax_number,'currency'=>$business->currency,'timezone'=>$business->timezone],'settings'=>$settings]]);
    }

    public function updateBusinessProfile(UpdateBusinessProfileRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->operations->updateBusinessProfile(
                app(Business::class),
                $request->validated(),
                (int) $request->user()->id,
            ),
        ]);
    }

    public function businessProfileEvents(): JsonResponse
    {
        $business = app(Business::class);

        $rows = DB::table('business_configuration_audits as bca')
            ->join('users as actor', 'actor.id', '=', 'bca.performed_by_user_id')
            ->where('bca.business_id', $business->id)
            ->where('bca.entity_type', 'business_profile')
            ->where('bca.entity_id', $business->id)
            ->orderByDesc('bca.performed_at')
            ->orderByDesc('bca.id')
            ->get([
                'bca.id',
                'bca.action',
                'bca.previous_state',
                'bca.new_state',
                'bca.performed_at',
                'actor.name as performed_by_name',
            ])
            ->map(fn (object $event): array => [
                'id' => $event->id,
                'action' => $event->action,
                'previous_state' => $event->previous_state ? json_decode($event->previous_state, true, 512, JSON_THROW_ON_ERROR) : null,
                'new_state' => $event->new_state ? json_decode($event->new_state, true, 512, JSON_THROW_ON_ERROR) : null,
                'performed_at' => $event->performed_at,
                'performed_by_name' => $event->performed_by_name,
            ]);

        return response()->json(['data' => $rows]);
    }

    public function settingsEvents(): JsonResponse
    {
        $business = app(Business::class);

        $rows = DB::table('business_configuration_audits as bca')
            ->join('users as actor', 'actor.id', '=', 'bca.performed_by_user_id')
            ->where('bca.business_id', $business->id)
            ->where('bca.entity_type', 'business_settings')
            ->where('bca.entity_id', $business->id)
            ->orderByDesc('bca.performed_at')
            ->orderByDesc('bca.id')
            ->get([
                'bca.id',
                'bca.action',
                'bca.previous_state',
                'bca.new_state',
                'bca.performed_at',
                'actor.name as performed_by_name',
            ])
            ->map(fn (object $event): array => [
                'id' => $event->id,
                'action' => $event->action,
                'previous_state' => $event->previous_state ? json_decode($event->previous_state, true, 512, JSON_THROW_ON_ERROR) : null,
                'new_state' => $event->new_state ? json_decode($event->new_state, true, 512, JSON_THROW_ON_ERROR) : null,
                'performed_at' => $event->performed_at,
                'performed_by_name' => $event->performed_by_name,
            ]);

        return response()->json(['data' => $rows]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $business=app(Business::class); $data=$request->validate(['receipt_footer'=>['nullable','string','max:500'],'service_charge_enabled'=>['required','boolean'],'low_stock_alerts'=>['required','boolean']]);
        $this->operations->updateSettings($business,$data,(int) $request->user()->id);
        return response()->json(['message'=>'Settings saved.']);
    }

    private function configurationEvents(string $entityType, string $entityId, string $table): array
    {
        $business = app(Business::class);

        abort_unless(
            DB::table($table)
                ->where('business_id', $business->id)
                ->where('id', $entityId)
                ->exists(),
            404,
        );

        return DB::table('business_configuration_audits as bca')
            ->join('users as actor', 'actor.id', '=', 'bca.performed_by_user_id')
            ->where('bca.business_id', $business->id)
            ->where('bca.entity_type', $entityType)
            ->where('bca.entity_id', $entityId)
            ->orderByDesc('bca.performed_at')
            ->orderByDesc('bca.id')
            ->get([
                'bca.id',
                'bca.action',
                'bca.previous_state',
                'bca.new_state',
                'bca.performed_at',
                'actor.name as performed_by_name',
            ])
            ->map(fn (object $event): array => [
                'id' => $event->id,
                'action' => $event->action,
                'previous_state' => $event->previous_state ? json_decode($event->previous_state, true, 512, JSON_THROW_ON_ERROR) : null,
                'new_state' => $event->new_state ? json_decode($event->new_state, true, 512, JSON_THROW_ON_ERROR) : null,
                'performed_at' => $event->performed_at,
                'performed_by_name' => $event->performed_by_name,
            ])
            ->all();
    }

    private function location(Request $request, Business $business): string
    {
        $id=(string)$request->query('location_id',$request->input('location_id','')); abort_unless($id,422,'Location is required.');
        abort_unless(DB::table('locations')->where('business_id',$business->id)->where('id',$id)->where('is_active',true)->exists(),422,'Invalid location.'); return $id;
    }
}
