<?php

use App\Models\Business;
use App\Models\FiscalizationProfile;
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

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

function vcmBusiness(string $name): Business
{
    return Business::query()->create([
        'name' => $name,
        'currency' => 'EUR',
        'timezone' => 'Europe/Berlin',
        'status' => 'active',
    ]);
}

function vcmLocation(Business $business, string $name, bool $active = true): Location
{
    return Location::query()->create([
        'business_id' => $business->getKey(),
        'name' => $name,
        'code' => Str::upper(Str::substr(Str::slug($name, ''), 0, 12)).Str::upper(Str::random(3)),
        'type' => 'bar_cafe',
        'is_active' => $active,
    ]);
}

function vcmUser(Business $business, array $permissions): User
{
    $user = User::query()->create([
        'name' => 'Venue Manager',
        'email' => Str::lower(Str::random(12)).'@example.test',
        'password' => 'Venue#Password123',
    ]);

    $role = Role::query()->create([
        'business_id' => $business->getKey(),
        'name' => 'Venue Role '.Str::random(5),
        'slug' => 'venue-role-'.Str::lower(Str::random(8)),
        'is_system' => false,
    ]);

    $role->permissions()->sync(
        Permission::query()->whereIn('key', $permissions)->pluck('id')
    );

    $user->businesses()->attach($business->getKey(), [
        'role_id' => $role->getKey(),
        'status' => 'active',
    ]);

    return $user;
}

function vcmHeaders(User $user, Business $business): array
{
    Sanctum::actingAs($user);

    return ['X-Business-Id' => $business->getKey()];
}

function vcmArea(object $case, array $headers, string $locationId, string $name = 'Main Room', int $sortOrder = 10): string
{
    return $case->postJson('/api/v1/management/venue/areas', [
        'location_id' => $locationId,
        'name' => $name,
        'sort_order' => $sortOrder,
    ], $headers)->assertCreated()->json('data.id');
}

function vcmTable(object $case, array $headers, string $locationId, string $areaId, string $name = 'T1', int $capacity = 4): string
{
    return $case->postJson('/api/v1/management/venue/tables', [
        'location_id' => $locationId,
        'venue_area_id' => $areaId,
        'name' => $name,
        'capacity' => $capacity,
    ], $headers)->assertCreated()->json('data.id');
}

test('areas and tables support audited tenant-scoped lifecycle and ordered management views', function (): void {
    $business = vcmBusiness('Venue CRUD');
    $location = vcmLocation($business, 'Main');
    $user = vcmUser($business, ['venue.manage', 'orders.view']);
    $headers = vcmHeaders($user, $business);

    $terrace = vcmArea($this, $headers, $location->id, 'Terrace', 20);
    $inside = vcmArea($this, $headers, $location->id, 'Inside', 10);

    $table = vcmTable($this, $headers, $location->id, $terrace, 'T-01', 6);

    $response = $this->getJson('/api/v1/management/venue?location_id='.$location->id, $headers)
        ->assertOk()
        ->assertJsonPath('data.areas.0.id', $inside)
        ->assertJsonPath('data.areas.1.id', $terrace)
        ->assertJsonPath('data.areas.1.table_count', 1)
        ->assertJsonPath('data.areas.1.active_table_count', 1)
        ->assertJsonPath('data.tables.0.id', $table)
        ->assertJsonPath('data.tables.0.area_name', 'Terrace')
        ->assertJsonPath('data.tables.0.capacity', 6)
        ->assertJsonPath('data.tables.0.open_order_count', 0);

    expect($response->json('data.tables.0.is_active'))->toBeTrue();

    $this->postJson('/api/v1/management/venue/areas', [
        'id' => $terrace,
        'location_id' => $location->id,
        'name' => 'Terrace Service',
        'sort_order' => 5,
    ], $headers)->assertOk()
        ->assertJsonPath('data.name', 'Terrace Service')
        ->assertJsonPath('data.sort_order', 5);

    $this->postJson('/api/v1/management/venue/tables', [
        'id' => $table,
        'location_id' => $location->id,
        'venue_area_id' => $inside,
        'name' => 'T-01',
        'capacity' => 8,
    ], $headers)->assertOk()
        ->assertJsonPath('data.venue_area_id', $inside)
        ->assertJsonPath('data.capacity', 8);

    $audits = DB::table('business_configuration_audits')
        ->where('business_id', $business->id)
        ->orderBy('performed_at')
        ->get();

    expect($audits->where('entity_type', 'venue_area')->where('action', 'created'))->toHaveCount(2)
        ->and($audits->where('entity_type', 'venue_area')->where('action', 'updated'))->toHaveCount(1)
        ->and($audits->where('entity_type', 'venue_table')->where('action', 'created'))->toHaveCount(1)
        ->and($audits->where('entity_type', 'venue_table')->where('action', 'updated'))->toHaveCount(1);

    $tableAudit = $audits->first(fn ($audit) => $audit->entity_type === 'venue_table' && $audit->action === 'updated');
    expect(json_decode($tableAudit->previous_state, true)['capacity'])->toBe(6)
        ->and(json_decode($tableAudit->new_state, true)['capacity'])->toBe(8)
        ->and(json_decode($tableAudit->new_state, true)['venue_area_id'])->toBe($inside);
});

test('area and table identities are case-insensitively unique inside one location', function (): void {
    $business = vcmBusiness('Venue Identity');
    $location = vcmLocation($business, 'Identity');
    $user = vcmUser($business, ['venue.manage']);
    $headers = vcmHeaders($user, $business);

    $area = vcmArea($this, $headers, $location->id, 'Terrace', 1);

    $this->postJson('/api/v1/management/venue/areas', [
        'location_id' => $location->id,
        'name' => 'terrace',
        'sort_order' => 2,
    ], $headers)->assertStatus(422)
        ->assertJsonValidationErrors('name');

    vcmTable($this, $headers, $location->id, $area, 'T1');

    $this->postJson('/api/v1/management/venue/tables', [
        'location_id' => $location->id,
        'venue_area_id' => $area,
        'name' => 't1',
        'capacity' => 2,
    ], $headers)->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

test('area disable requires every table inactive and table enable requires an active area', function (): void {
    $business = vcmBusiness('Venue Status');
    $location = vcmLocation($business, 'Status');
    $user = vcmUser($business, ['venue.manage']);
    $headers = vcmHeaders($user, $business);

    $area = vcmArea($this, $headers, $location->id);
    $table = vcmTable($this, $headers, $location->id, $area);

    $this->patchJson("/api/v1/management/venue/areas/{$area}/status", [
        'is_active' => false,
    ], $headers)->assertStatus(422)
        ->assertJsonValidationErrors('area');

    $this->patchJson("/api/v1/management/venue/tables/{$table}/status", [
        'is_active' => false,
    ], $headers)->assertOk()
        ->assertJsonPath('data.is_active', false);

    $this->patchJson("/api/v1/management/venue/areas/{$area}/status", [
        'is_active' => false,
    ], $headers)->assertOk()
        ->assertJsonPath('data.is_active', false);

    $this->patchJson("/api/v1/management/venue/tables/{$table}/status", [
        'is_active' => true,
    ], $headers)->assertStatus(422)
        ->assertJsonValidationErrors('table');

    $this->patchJson("/api/v1/management/venue/areas/{$area}/status", [
        'is_active' => true,
    ], $headers)->assertOk();

    $this->patchJson("/api/v1/management/venue/tables/{$table}/status", [
        'is_active' => true,
    ], $headers)->assertOk()
        ->assertJsonPath('data.is_active', true);
});

test('table disable is blocked while an active order still references it', function (): void {
    $business = vcmBusiness('Table Order Guard');
    $location = vcmLocation($business, 'Orders');
    $user = vcmUser($business, ['venue.manage']);
    $headers = vcmHeaders($user, $business);
    $area = vcmArea($this, $headers, $location->id);
    $table = vcmTable($this, $headers, $location->id, $area);

    $order = (string) Str::ulid();
    DB::table('orders')->insert([
        'id' => $order,
        'business_id' => $business->id,
        'location_id' => $location->id,
        'venue_table_id' => $table,
        'opened_by_user_id' => $user->id,
        'number' => 'ORD-VENUE-1',
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

    $this->patchJson("/api/v1/management/venue/tables/{$table}/status", [
        'is_active' => false,
    ], $headers)->assertStatus(422)
        ->assertJsonValidationErrors('table');

    DB::table('orders')->where('id', $order)->update([
        'status' => 'closed',
        'closed_at' => now(),
        'updated_at' => now(),
    ]);

    $this->patchJson("/api/v1/management/venue/tables/{$table}/status", [
        'is_active' => false,
    ], $headers)->assertOk()
        ->assertJsonPath('data.is_active', false);
});

test('table configuration cannot cross tenant or location boundaries', function (): void {
    $businessA = vcmBusiness('Venue Tenant A');
    $businessB = vcmBusiness('Venue Tenant B');
    $locationA = vcmLocation($businessA, 'A');
    $locationA2 = vcmLocation($businessA, 'A2');
    $locationB = vcmLocation($businessB, 'B');
    $userA = vcmUser($businessA, ['venue.manage']);
    $userB = vcmUser($businessB, ['venue.manage']);

    $headersA = vcmHeaders($userA, $businessA);
    $headersB = vcmHeaders($userB, $businessB);

    $areaA = vcmArea($this, $headersA, $locationA->id, 'A Area');
    $areaA2 = vcmArea($this, $headersA, $locationA2->id, 'A2 Area');
    $areaB = vcmArea($this, $headersB, $locationB->id, 'B Area');
    $tableA = vcmTable($this, $headersA, $locationA->id, $areaA, 'A-T1');

    $this->postJson('/api/v1/management/venue/tables', [
        'id' => $tableA,
        'location_id' => $locationA2->id,
        'venue_area_id' => $areaA2,
        'name' => 'Moved Cross Location',
        'capacity' => 4,
    ], $headersA)->assertStatus(422)
        ->assertJsonValidationErrors('location_id');

    $this->postJson('/api/v1/management/venue/tables', [
        'location_id' => $locationA->id,
        'venue_area_id' => $areaB,
        'name' => 'Foreign Area Table',
        'capacity' => 4,
    ], $headersA)->assertStatus(422)
        ->assertJsonValidationErrors('venue_area_id');

    $this->patchJson("/api/v1/management/venue/tables/{$tableA}/status", [
        'is_active' => false,
    ], $headersB)->assertNotFound();
});

test('cash register lifecycle is audited guarded and invalidates fiscal preflight topology', function (): void {
    $business = vcmBusiness('Register Lifecycle');
    $location = vcmLocation($business, 'Register');
    $user = vcmUser($business, ['cash_registers.manage']);
    $headers = vcmHeaders($user, $business);

    $profile = FiscalizationProfile::query()->create([
        'business_id' => $business->id,
        'provider' => 'direct_dpt',
        'environment' => 'production',
        'status' => 'active',
        'software_code' => 'aa123aa123',
        'endpoint' => 'https://prod.example.test',
        'preflight_checked_at' => now(),
        'preflight_status' => 'ready',
        'production_activated_at' => now(),
        'production_activated_by_user_id' => $user->id,
    ]);

    $created = $this->postJson('/api/v1/management/cash-registers', [
        'location_id' => $location->id,
        'name' => 'Main Till',
        'code' => 'main_1',
    ], $headers)->assertCreated()
        ->assertJsonPath('data.name', 'Main Till')
        ->assertJsonPath('data.code', 'MAIN_1')
        ->assertJsonPath('data.is_active', true);

    $register = $created->json('data.id');

    $profile->refresh();
    expect($profile->preflight_checked_at)->toBeNull()
        ->and($profile->preflight_status)->toBeNull();

    $this->getJson('/api/v1/management/cash-registers?location_id='.$location->id, $headers)
        ->assertOk()
        ->assertJsonPath('data.0.id', $register)
        ->assertJsonPath('data.0.open_session_count', 0);

    $profile->forceFill([
        'preflight_checked_at' => now(),
        'preflight_status' => 'ready',
    ])->save();

    $session = (string) Str::ulid();
    DB::table('cash_sessions')->insert([
        'id' => $session,
        'business_id' => $business->id,
        'location_id' => $location->id,
        'cash_register_id' => $register,
        'opened_by_user_id' => $user->id,
        'base_currency' => 'EUR',
        'opening_cash' => '100.0000',
        'status' => 'open',
        'opened_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->patchJson("/api/v1/management/cash-registers/{$register}/status", [
        'is_active' => false,
    ], $headers)->assertStatus(422)
        ->assertJsonValidationErrors('register');

    DB::table('cash_sessions')->where('id', $session)->update([
        'status' => 'closed',
        'closed_by_user_id' => $user->id,
        'expected_cash' => '100.0000',
        'counted_cash' => '100.0000',
        'cash_difference' => '0.0000',
        'closed_at' => now(),
        'updated_at' => now(),
    ]);

    $this->patchJson("/api/v1/management/cash-registers/{$register}/status", [
        'is_active' => false,
    ], $headers)->assertOk()
        ->assertJsonPath('data.is_active', false);

    $profile->refresh();
    expect($profile->preflight_checked_at)->toBeNull()
        ->and($profile->preflight_status)->toBeNull();

    $this->patchJson("/api/v1/management/cash-registers/{$register}/status", [
        'is_active' => true,
    ], $headers)->assertOk()
        ->assertJsonPath('data.is_active', true);

    $audits = DB::table('business_configuration_audits')
        ->where('business_id', $business->id)
        ->where('entity_type', 'cash_register')
        ->where('entity_id', $register)
        ->get();

    expect($audits->where('action', 'created'))->toHaveCount(1)
        ->and($audits->where('action', 'status_changed'))->toHaveCount(2);
});

test('cash register names are unique per location and codes are unique across the business', function (): void {
    $business = vcmBusiness('Register Identity');
    $locationA = vcmLocation($business, 'Till A');
    $locationB = vcmLocation($business, 'Till B');
    $user = vcmUser($business, ['cash_registers.manage']);
    $headers = vcmHeaders($user, $business);

    $this->postJson('/api/v1/management/cash-registers', [
        'location_id' => $locationA->id,
        'name' => 'Main Till',
        'code' => 'POS1',
    ], $headers)->assertCreated();

    $this->postJson('/api/v1/management/cash-registers', [
        'location_id' => $locationA->id,
        'name' => 'main till',
        'code' => 'POS2',
    ], $headers)->assertStatus(422)
        ->assertJsonValidationErrors('name');

    $this->postJson('/api/v1/management/cash-registers', [
        'location_id' => $locationB->id,
        'name' => 'Other Till',
        'code' => 'pos1',
    ], $headers)->assertStatus(422)
        ->assertJsonValidationErrors('code');
});

test('venue and register management permissions are independently enforced', function (): void {
    $business = vcmBusiness('Venue RBAC');
    $location = vcmLocation($business, 'RBAC');
    $venueManager = vcmUser($business, ['venue.manage']);
    $registerManager = vcmUser($business, ['cash_registers.manage']);

    $venueHeaders = vcmHeaders($venueManager, $business);

    $this->getJson('/api/v1/management/venue?location_id='.$location->id, $venueHeaders)->assertOk();
    $this->getJson('/api/v1/management/cash-registers?location_id='.$location->id, $venueHeaders)->assertForbidden();

    $registerHeaders = vcmHeaders($registerManager, $business);
    $this->getJson('/api/v1/management/cash-registers?location_id='.$location->id, $registerHeaders)->assertOk();
    $this->getJson('/api/v1/management/venue?location_id='.$location->id, $registerHeaders)->assertForbidden();
});

test('operational venue endpoint hides inactive areas tables and rejects inactive locations', function (): void {
    $business = vcmBusiness('Venue Operational');
    $location = vcmLocation($business, 'Operational');
    $user = vcmUser($business, ['venue.manage', 'orders.view']);
    $headers = vcmHeaders($user, $business);

    $activeArea = vcmArea($this, $headers, $location->id, 'Active');
    $inactiveArea = vcmArea($this, $headers, $location->id, 'Inactive');

    $activeTable = vcmTable($this, $headers, $location->id, $activeArea, 'A1');
    $inactiveTable = vcmTable($this, $headers, $location->id, $activeArea, 'A2');
    $hiddenTable = vcmTable($this, $headers, $location->id, $inactiveArea, 'I1');

    $this->patchJson("/api/v1/management/venue/tables/{$inactiveTable}/status", ['is_active' => false], $headers)->assertOk();
    $this->patchJson("/api/v1/management/venue/tables/{$hiddenTable}/status", ['is_active' => false], $headers)->assertOk();
    $this->patchJson("/api/v1/management/venue/areas/{$inactiveArea}/status", ['is_active' => false], $headers)->assertOk();

    $operational = $this->getJson('/api/v1/venue?location_id='.$location->id, $headers)
        ->assertOk()
        ->json('data');

    expect($operational)->toHaveCount(1)
        ->and($operational[0]['id'])->toBe($activeArea)
        ->and(collect($operational[0]['tables'])->pluck('id')->all())->toBe([$activeTable]);

    $location->update(['is_active' => false]);

    $this->getJson('/api/v1/venue?location_id='.$location->id, $headers)
        ->assertStatus(422)
        ->assertJsonValidationErrors('location_id');
});
