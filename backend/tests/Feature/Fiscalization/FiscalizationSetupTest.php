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

function fstBusiness(string $name): Business
{
    return Business::query()->create([
        'name'=>$name,'legal_name'=>$name.' sh.p.k.','tax_number'=>'L12345678A',
        'currency'=>'ALL','timezone'=>'Europe/Tirane','status'=>'active',
    ]);
}

function fstLocation(Business $business,string $code): Location
{
    return Location::query()->create([
        'business_id'=>$business->id,'name'=>'Location '.$code,'code'=>$code,'type'=>'bar','is_active'=>true,
    ]);
}

function fstUser(Business $business,array $permissions): User
{
    $user=User::query()->create([
        'name'=>'Fiscal Setup User','email'=>Str::lower(Str::random(10)).'@example.test','password'=>bcrypt('password'),
    ]);
    $role=Role::query()->create([
        'business_id'=>$business->id,'name'=>'Fiscal Setup','slug'=>'fiscal-'.Str::lower(Str::random(8)),'is_system'=>false,
    ]);
    $role->permissions()->sync(Permission::query()->whereIn('key',$permissions)->pluck('id'));
    $user->businesses()->attach($business->id,['role_id'=>$role->id,'status'=>'active']);
    Sanctum::actingAs($user);
    return $user;
}

test('fiscal setup is tenant isolated and persists normalized official codes', function (): void {
    $a=fstBusiness('Setup A');$b=fstBusiness('Setup B');
    $la=fstLocation($a,'A1');$lb=fstLocation($b,'B1');
    $user=fstUser($a,['fiscalization.view','fiscalization.manage']);

    $registerA=(string)Str::ulid();$registerB=(string)Str::ulid();
    DB::table('cash_registers')->insert([
        ['id'=>$registerA,'business_id'=>$a->id,'location_id'=>$la->id,'name'=>'A Register','code'=>'AR','is_active'=>true,'created_at'=>now(),'updated_at'=>now()],
        ['id'=>$registerB,'business_id'=>$b->id,'location_id'=>$lb->id,'name'=>'B Register','code'=>'BR','is_active'=>true,'created_at'=>now(),'updated_at'=>now()],
    ]);

    $headers=['X-Business-Id'=>$a->id];
    $this->putJson('/api/v1/fiscalization/setup',[
        'locations'=>[['id'=>$la->id,'fiscal_business_unit_code'=>'AB123AB123']],
        'cash_registers'=>[['id'=>$registerA,'fiscal_tcr_code'=>'CD123CD123']],
        'operators'=>[['user_id'=>$user->id,'fiscal_operator_code'=>'EF123EF123']],
    ],$headers)->assertOk()
        ->assertJsonPath('data.locations.0.fiscal_business_unit_code','ab123ab123')
        ->assertJsonPath('data.cash_registers.0.fiscal_tcr_code','cd123cd123')
        ->assertJsonPath('data.operators.0.fiscal_operator_code','ef123ef123');

    expect(DB::table('locations')->where('id',$lb->id)->value('fiscal_business_unit_code'))->toBeNull()
        ->and(DB::table('cash_registers')->where('id',$registerB)->value('fiscal_tcr_code'))->toBeNull();

    $this->getJson('/api/v1/fiscalization/setup',$headers)->assertOk()
        ->assertJsonCount(1,'data.locations')
        ->assertJsonCount(1,'data.cash_registers')
        ->assertJsonCount(1,'data.operators');
});

test('fiscal setup rejects foreign tenant rows duplicate codes and malformed values', function (): void {
    $a=fstBusiness('Setup Validation A');$b=fstBusiness('Setup Validation B');
    $la=fstLocation($a,'A2');$lb=fstLocation($b,'B2');
    fstUser($a,['fiscalization.view','fiscalization.manage']);
    $registerA=(string)Str::ulid();$registerA2=(string)Str::ulid();
    DB::table('cash_registers')->insert([
        ['id'=>$registerA,'business_id'=>$a->id,'location_id'=>$la->id,'name'=>'A1','code'=>'A1','is_active'=>true,'created_at'=>now(),'updated_at'=>now()],
        ['id'=>$registerA2,'business_id'=>$a->id,'location_id'=>$la->id,'name'=>'A2','code'=>'A2','is_active'=>true,'created_at'=>now(),'updated_at'=>now()],
    ]);

    $headers=['X-Business-Id'=>$a->id];

    $this->putJson('/api/v1/fiscalization/setup',[
        'locations'=>[['id'=>$lb->id,'fiscal_business_unit_code'=>'aa123aa123']],
    ],$headers)->assertStatus(422)->assertJsonValidationErrors('locations');

    $this->putJson('/api/v1/fiscalization/setup',[
        'cash_registers'=>[
            ['id'=>$registerA,'fiscal_tcr_code'=>'aa123aa123'],
            ['id'=>$registerA2,'fiscal_tcr_code'=>'aa123aa123'],
        ],
    ],$headers)->assertStatus(422)->assertJsonValidationErrors('fiscalization');

    $this->putJson('/api/v1/fiscalization/setup',[
        'cash_registers'=>[['id'=>$registerA,'fiscal_tcr_code'=>'INVALID']],
    ],$headers)->assertStatus(422)->assertJsonValidationErrors('cash_registers.0.fiscal_tcr_code');
});

test('fiscal setup update requires manage permission', function (): void {
    $business=fstBusiness('Setup RBAC');$location=fstLocation($business,'RBAC');
    fstUser($business,['fiscalization.view']);

    $this->putJson('/api/v1/fiscalization/setup',[
        'locations'=>[['id'=>$location->id,'fiscal_business_unit_code'=>'aa123aa123']],
    ],['X-Business-Id'=>$business->id])->assertForbidden();

    expect(DB::table('locations')->where('id',$location->id)->value('fiscal_business_unit_code'))->toBeNull();
});
