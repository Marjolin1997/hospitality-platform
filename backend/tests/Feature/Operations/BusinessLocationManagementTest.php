<?php

use App\Models\Business;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

function blmBusiness(string $name): Business
{
    return Business::query()->create([
        'name' => $name,
        'currency' => 'EUR',
        'timezone' => 'Europe/Berlin',
        'status' => 'active',
    ]);
}

function blmUser(Business $business, array $permissionKeys = ['business.settings.manage']): User
{
    $user = User::query()->create([
        'name' => 'Location Manager',
        'email' => Str::lower(Str::random(12)).'@example.test',
        'password' => 'test-password',
    ]);

    $role = Role::query()->create([
        'business_id' => $business->getKey(),
        'name' => 'Location Role '.Str::random(4),
        'slug' => 'location-'.Str::lower(Str::random(8)),
        'is_system' => false,
    ]);

    $role->permissions()->sync(
        Permission::query()->whereIn('key', $permissionKeys)->pluck('id')
    );

    $user->businesses()->attach($business->getKey(), [
        'role_id' => $role->getKey(),
        'status' => 'active',
    ]);

    return $user;
}

function blmHeaders(User $user, Business $business): array
{
    Sanctum::actingAs($user);

    return ['X-Business-Id' => $business->getKey()];
}

test('location management creates updates lists and audits tenant state', function (): void {
    $business = blmBusiness('Location CRUD');
    $user = blmUser($business);
    $headers = blmHeaders($user, $business);

    $locationId = $this->postJson('/api/v1/management/locations', [
        'name' => 'Main Branch',
        'code' => 'main_1',
        'type' => 'bar_cafe',
        'address' => '1 Main Street',
    ], $headers)->assertCreated()
        ->assertJsonPath('data.name', 'Main Branch')
        ->assertJsonPath('data.code', 'MAIN_1')
        ->assertJsonPath('data.is_active', true)
        ->json('data.id');

    $list = $this->getJson('/api/v1/management/locations', $headers)->assertOk()->json('data');
    expect($list)->toHaveCount(1)
        ->and($list[0]['id'])->toBe($locationId)
        ->and($list[0]['table_count'])->toBe(0)
        ->and($list[0]['cash_register_count'])->toBe(0)
        ->and($list[0]['open_order_count'])->toBe(0)
        ->and($list[0]['open_cash_session_count'])->toBe(0)
        ->and($list[0]['is_last_active'])->toBeTrue();

    $this->postJson('/api/v1/management/locations', [
        'id' => $locationId,
        'name' => 'Central Bar',
        'code' => 'central-01',
        'type' => 'bar',
        'address' => '2 Central Street',
    ], $headers)->assertOk()
        ->assertJsonPath('data.name', 'Central Bar')
        ->assertJsonPath('data.code', 'CENTRAL-01')
        ->assertJsonPath('data.type', 'bar');

    $audits = DB::table('business_location_audits')
        ->where('business_id', $business->getKey())
        ->where('location_id', $locationId)
        ->orderBy('performed_at')
        ->get();

    expect($audits)->toHaveCount(2)
        ->and($audits[0]->action)->toBe('created')
        ->and($audits[0]->performed_by_user_id)->toBe($user->id)
        ->and($audits[1]->action)->toBe('updated')
        ->and(json_decode($audits[1]->previous_state, true)['name'])->toBe('Main Branch')
        ->and(json_decode($audits[1]->new_state, true)['name'])->toBe('Central Bar');
});

test('location names and codes are case-insensitively unique inside a business', function (): void {
    $businessA = blmBusiness('Location Unique A');
    $businessB = blmBusiness('Location Unique B');
    $userA = blmUser($businessA);
    $userB = blmUser($businessB);

    $payload = [
        'name' => 'Main Branch',
        'code' => 'MAIN',
        'type' => 'cafe',
        'address' => null,
    ];

    $this->postJson('/api/v1/management/locations', $payload, blmHeaders($userA, $businessA))->assertCreated();

    $this->postJson('/api/v1/management/locations', [...$payload, 'name' => 'main branch', 'code' => 'SECOND'], blmHeaders($userA, $businessA))
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');

    $this->postJson('/api/v1/management/locations', [...$payload, 'name' => 'Second', 'code' => 'main'], blmHeaders($userA, $businessA))
        ->assertStatus(422)
        ->assertJsonValidationErrors('code');

    $this->postJson('/api/v1/management/locations', $payload, blmHeaders($userB, $businessB))->assertCreated();
});

test('location disable preserves one active branch and blocks live operational dependencies', function (): void {
    $business = blmBusiness('Location Disable');
    $user = blmUser($business);
    $headers = blmHeaders($user, $business);

    $main = $this->postJson('/api/v1/management/locations', [
        'name' => 'Main',
        'code' => 'MAIN',
        'type' => 'bar',
        'address' => null,
    ], $headers)->assertCreated()->json('data.id');

    $this->patchJson("/api/v1/management/locations/{$main}/status", ['is_active' => false], $headers)
        ->assertStatus(422)
        ->assertJsonValidationErrors('location');

    $second = $this->postJson('/api/v1/management/locations', [
        'name' => 'Terrace',
        'code' => 'TERRACE',
        'type' => 'cafe',
        'address' => null,
    ], $headers)->assertCreated()->json('data.id');

    $orderId = (string) Str::ulid();
    DB::table('orders')->insert([
        'id' => $orderId,
        'business_id' => $business->getKey(),
        'location_id' => $second,
        'opened_by_user_id' => $user->id,
        'number' => 'ORD-LOC-1',
        'type' => 'table',
        'status' => 'open',
        'currency' => 'EUR',
        'subtotal' => '0.0000',
        'discount_total' => '0.0000',
        'tax_total' => '0.0000',
        'grand_total' => '0.0000',
        'opened_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->patchJson("/api/v1/management/locations/{$second}/status", ['is_active' => false], $headers)
        ->assertStatus(422)
        ->assertJsonValidationErrors('location');

    DB::table('orders')->where('id', $orderId)->update(['status' => 'closed', 'closed_at' => now(), 'updated_at' => now()]);

    $registerId = (string) Str::ulid();
    DB::table('cash_registers')->insert([
        'id' => $registerId,
        'business_id' => $business->getKey(),
        'location_id' => $second,
        'name' => 'Terrace Till',
        'code' => 'T-TILL',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $sessionId = (string) Str::ulid();
    DB::table('cash_sessions')->insert([
        'id' => $sessionId,
        'business_id' => $business->getKey(),
        'location_id' => $second,
        'cash_register_id' => $registerId,
        'opened_by_user_id' => $user->id,
        'base_currency' => 'EUR',
        'opening_cash' => '50.0000',
        'status' => 'open',
        'opened_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->patchJson("/api/v1/management/locations/{$second}/status", ['is_active' => false], $headers)
        ->assertStatus(422)
        ->assertJsonValidationErrors('location');

    DB::table('cash_sessions')->where('id', $sessionId)->update([
        'status' => 'closed',
        'closed_by_user_id' => $user->id,
        'expected_cash' => '50.0000',
        'counted_cash' => '50.0000',
        'cash_difference' => '0.0000',
        'closed_at' => now(),
        'updated_at' => now(),
    ]);

    $this->patchJson("/api/v1/management/locations/{$second}/status", ['is_active' => false], $headers)
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    $me = $this->getJson('/api/v1/auth/me')->assertOk()->json('data.businesses.0.locations');
    expect(collect($me)->pluck('id')->all())->toBe([$main]);

    $this->patchJson("/api/v1/management/locations/{$second}/status", ['is_active' => true], $headers)
        ->assertOk()
        ->assertJsonPath('data.is_active', true);

    expect(DB::table('business_location_audits')->where('location_id', $second)->where('action', 'status_changed')->count())->toBe(2);
});

test('location mutation cannot cross tenant boundaries', function (): void {
    $businessA = blmBusiness('Location Tenant A');
    $businessB = blmBusiness('Location Tenant B');
    $userA = blmUser($businessA);
    $userB = blmUser($businessB);

    $foreign = $this->postJson('/api/v1/management/locations', [
        'name' => 'Foreign',
        'code' => 'FOREIGN',
        'type' => 'pub',
        'address' => null,
    ], blmHeaders($userB, $businessB))->assertCreated()->json('data.id');

    $headersA = blmHeaders($userA, $businessA);

    $this->postJson('/api/v1/management/locations', [
        'id' => $foreign,
        'name' => 'Hijacked',
        'code' => 'HIJACK',
        'type' => 'bar',
        'address' => null,
    ], $headersA)->assertNotFound();

    $this->patchJson("/api/v1/management/locations/{$foreign}/status", ['is_active' => false], $headersA)
        ->assertNotFound();

    expect(DB::table('locations')->where('id', $foreign)->value('name'))->toBe('Foreign');
});

test('location management requires business settings permission', function (): void {
    $business = blmBusiness('Location Gate');
    $viewer = blmUser($business, ['orders.view']);
    $headers = blmHeaders($viewer, $business);

    $this->getJson('/api/v1/management/locations', $headers)->assertForbidden();
    $this->postJson('/api/v1/management/locations', [
        'name' => 'Blocked',
        'code' => 'BLOCKED',
        'type' => 'bar',
        'address' => null,
    ], $headers)->assertForbidden();
});
