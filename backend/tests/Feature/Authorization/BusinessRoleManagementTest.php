<?php

use App\Models\Business;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Authorization\ProvisionBusinessRoles;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleTemplateSeeder::class);
});

function brmBusiness(string $name): Business
{
    return Business::query()->create([
        'name' => $name,
        'currency' => 'ALL',
        'timezone' => 'Europe/Tirane',
        'status' => 'active',
    ]);
}

function brmActor(Business $business, array $permissions = ['*']): User
{
    $user = User::query()->create([
        'name' => 'Role Manager',
        'email' => Str::lower(Str::random(12)).'@example.test',
        'password' => 'test-password',
    ]);

    $role = Role::query()->create([
        'business_id' => $business->getKey(),
        'name' => 'Role Manager',
        'slug' => 'role-manager-'.Str::lower(Str::random(6)),
        'is_system' => false,
    ]);

    $query = Permission::query();
    if ($permissions !== ['*']) {
        $query->whereIn('key', $permissions);
    }

    $role->permissions()->sync($query->pluck('id'));
    $user->businesses()->attach($business->getKey(), [
        'role_id' => $role->getKey(),
        'status' => 'active',
    ]);

    return $user;
}

function brmHeaders(User $user, Business $business): array
{
    Sanctum::actingAs($user);

    return ['X-Business-Id' => $business->getKey()];
}

test('custom role lifecycle is tenant scoped audited and exposes the permission catalog', function (): void {
    $business = brmBusiness('Custom Roles');
    $actor = brmActor($business);
    $headers = brmHeaders($actor, $business);

    $created = $this->postJson('/api/v1/roles', [
        'name' => 'Floor Lead',
        'permissions' => ['products.view', 'orders.view'],
    ], $headers)->assertCreated()
        ->assertJsonPath('data.name', 'Floor Lead')
        ->assertJsonPath('data.is_system', false)
        ->assertJsonPath('data.member_count', 0);

    $roleId = $created->json('data.id');
    $role = Role::query()->findOrFail($roleId);

    expect($role->business_id)->toBe($business->getKey())
        ->and($role->is_system)->toBeFalse()
        ->and($role->slug)->toStartWith('custom-floor-lead-')
        ->and($role->permissions()->orderBy('key')->pluck('key')->all())->toBe(['orders.view', 'products.view']);

    $createAudit = DB::table('business_role_audits')->where('role_id', $roleId)->where('action', 'created')->first();
    expect($createAudit)->not->toBeNull()
        ->and($createAudit->performed_by_user_id)->toBe($actor->id)
        ->and(json_decode($createAudit->new_permissions, true))->toBe(['orders.view', 'products.view']);

    $index = $this->getJson('/api/v1/roles', $headers)->assertOk()->json('data');
    expect(collect($index['roles'])->firstWhere('id', $roleId)['name'])->toBe('Floor Lead')
        ->and(collect($index['permissions'])->pluck('key'))->toContain('orders.view', 'roles.manage', 'fiscalization.manage');

    $this->putJson("/api/v1/roles/{$roleId}", [
        'name' => 'Service Lead',
        'permissions' => ['orders.view', 'orders.update', 'products.view'],
    ], $headers)->assertOk()
        ->assertJsonPath('data.name', 'Service Lead');

    $updated = Role::query()->findOrFail($roleId);
    expect($updated->slug)->toBe($role->slug)
        ->and($updated->permissions()->orderBy('key')->pluck('key')->all())
        ->toBe(['orders.update', 'orders.view', 'products.view']);

    $updateAudit = DB::table('business_role_audits')->where('role_id', $roleId)->where('action', 'updated')->first();
    expect($updateAudit)->not->toBeNull()
        ->and($updateAudit->previous_name)->toBe('Floor Lead')
        ->and($updateAudit->new_name)->toBe('Service Lead')
        ->and(json_decode($updateAudit->previous_permissions, true))->toBe(['orders.view', 'products.view'])
        ->and(json_decode($updateAudit->new_permissions, true))->toBe(['orders.update', 'orders.view', 'products.view']);

    $auditCount = DB::table('business_role_audits')->where('role_id', $roleId)->count();
    $this->putJson("/api/v1/roles/{$roleId}", [
        'name' => 'Service Lead',
        'permissions' => ['products.view', 'orders.update', 'orders.view'],
    ], $headers)->assertOk();
    expect(DB::table('business_role_audits')->where('role_id', $roleId)->count())->toBe($auditCount);

    $this->deleteJson("/api/v1/roles/{$roleId}", [], $headers)->assertOk()
        ->assertJsonPath('message', 'Custom role deleted.');

    expect(Role::query()->whereKey($roleId)->exists())->toBeFalse();
    $deleteAudit = DB::table('business_role_audits')->where('role_id', $roleId)->where('action', 'deleted')->first();
    expect($deleteAudit)->not->toBeNull()
        ->and($deleteAudit->previous_name)->toBe('Service Lead')
        ->and($deleteAudit->new_name)->toBeNull()
        ->and(json_decode($deleteAudit->previous_permissions, true))->toBe(['orders.update', 'orders.view', 'products.view']);
});

test('custom role names are case-insensitively unique inside a business', function (): void {
    $business = brmBusiness('Unique Roles');
    $actor = brmActor($business);
    $headers = brmHeaders($actor, $business);

    $this->postJson('/api/v1/roles', [
        'name' => 'Floor Lead',
        'permissions' => ['orders.view'],
    ], $headers)->assertCreated();

    $this->postJson('/api/v1/roles', [
        'name' => 'floor lead',
        'permissions' => ['orders.view'],
    ], $headers)->assertStatus(422)
        ->assertJsonValidationErrors('name');

    expect(Role::query()->where('business_id', $business->getKey())->whereRaw('LOWER(name) = ?', ['floor lead'])->count())->toBe(1);
});

test('role managers cannot delegate or modify permissions above their own access level', function (): void {
    $business = brmBusiness('Delegation Guard');
    $owner = brmActor($business);
    $ownerHeaders = brmHeaders($owner, $business);

    $elevatedRole = $this->postJson('/api/v1/roles', [
        'name' => 'Elevated Custom',
        'permissions' => ['orders.view', 'fiscalization.manage'],
    ], $ownerHeaders)->assertCreated()->json('data.id');

    $limited = brmActor($business, ['roles.manage', 'orders.view']);
    $limitedHeaders = brmHeaders($limited, $business);

    $catalog = $this->getJson('/api/v1/roles', $limitedHeaders)
        ->assertOk()
        ->json('data.permissions');

    expect(collect($catalog)->pluck('key')->all())
        ->toContain('roles.manage', 'orders.view')
        ->not->toContain('fiscalization.manage');

    $this->postJson('/api/v1/roles', [
        'name' => 'Escalated Role',
        'permissions' => ['orders.view', 'fiscalization.manage'],
    ], $limitedHeaders)->assertStatus(422)
        ->assertJsonValidationErrors('permissions');

    $this->putJson("/api/v1/roles/{$elevatedRole}", [
        'name' => 'Elevated Custom Changed',
        'permissions' => ['orders.view'],
    ], $limitedHeaders)->assertStatus(422)
        ->assertJsonValidationErrors('role');

    $this->deleteJson("/api/v1/roles/{$elevatedRole}", [], $limitedHeaders)
        ->assertStatus(422)
        ->assertJsonValidationErrors('role');

    $this->postJson('/api/v1/roles', [
        'name' => 'Allowed Delegation',
        'permissions' => ['orders.view'],
    ], $limitedHeaders)->assertCreated();

    expect(Role::query()->where('business_id', $business->getKey())->where('name', 'Escalated Role')->exists())->toBeFalse()
        ->and(Role::query()->whereKey($elevatedRole)->value('name'))->toBe('Elevated Custom');
});

test('system role templates are immutable through custom role endpoints', function (): void {
    $business = brmBusiness('System Roles');
    app(ProvisionBusinessRoles::class)->handle($business);
    $actor = brmActor($business);
    $headers = brmHeaders($actor, $business);
    $owner = Role::query()->where('business_id', $business->getKey())->where('slug', 'owner')->firstOrFail();

    $payload = [
        'name' => 'Changed Owner',
        'permissions' => ['orders.view'],
    ];

    $this->putJson("/api/v1/roles/{$owner->id}", $payload, $headers)
        ->assertStatus(422)
        ->assertJsonValidationErrors('role');

    $this->deleteJson("/api/v1/roles/{$owner->id}", [], $headers)
        ->assertStatus(422)
        ->assertJsonValidationErrors('role');

    expect($owner->fresh()->name)->toBe('Owner')
        ->and($owner->fresh()->is_system)->toBeTrue();
});

test('assigned custom roles cannot be deleted until every membership is reassigned', function (): void {
    $business = brmBusiness('Assigned Role');
    $actor = brmActor($business);
    $headers = brmHeaders($actor, $business);

    $roleId = $this->postJson('/api/v1/roles', [
        'name' => 'Shift Supervisor',
        'permissions' => ['orders.view'],
    ], $headers)->assertCreated()->json('data.id');

    $member = User::query()->create([
        'name' => 'Assigned Member',
        'email' => 'assigned-role@example.test',
        'password' => 'test-password',
    ]);
    $member->businesses()->attach($business->getKey(), [
        'role_id' => $roleId,
        'status' => 'inactive',
    ]);

    $this->deleteJson("/api/v1/roles/{$roleId}", [], $headers)
        ->assertStatus(422)
        ->assertJsonValidationErrors('role');

    expect(Role::query()->whereKey($roleId)->exists())->toBeTrue();
});

test('role mutations cannot cross tenant boundaries', function (): void {
    $businessA = brmBusiness('Tenant A');
    $businessB = brmBusiness('Tenant B');
    $actorA = brmActor($businessA);
    $actorB = brmActor($businessB);

    $roleB = $this->postJson('/api/v1/roles', [
        'name' => 'Tenant B Custom',
        'permissions' => ['orders.view'],
    ], brmHeaders($actorB, $businessB))->assertCreated()->json('data.id');

    $headersA = brmHeaders($actorA, $businessA);

    $this->putJson("/api/v1/roles/{$roleB}", [
        'name' => 'Hijacked',
        'permissions' => ['orders.view'],
    ], $headersA)->assertNotFound();

    $this->deleteJson("/api/v1/roles/{$roleB}", [], $headersA)->assertNotFound();

    expect(Role::query()->whereKey($roleB)->value('name'))->toBe('Tenant B Custom');
});

test('roles manage permission is required and invalid permission keys are rejected', function (): void {
    $business = brmBusiness('Role Permission Gate');
    $viewer = brmActor($business, ['users.view']);
    $viewerHeaders = brmHeaders($viewer, $business);

    $this->getJson('/api/v1/roles', $viewerHeaders)->assertForbidden();
    $this->postJson('/api/v1/roles', [
        'name' => 'Unauthorized',
        'permissions' => ['orders.view'],
    ], $viewerHeaders)->assertForbidden();

    $manager = brmActor($business);
    $managerHeaders = brmHeaders($manager, $business);

    $this->postJson('/api/v1/roles', [
        'name' => 'Invalid Permission',
        'permissions' => ['orders.view', 'does.not.exist'],
    ], $managerHeaders)->assertStatus(422)
        ->assertJsonValidationErrors('permissions.1');

    expect(Role::query()->where('business_id', $business->getKey())->where('name', 'Invalid Permission')->exists())->toBeFalse();
});
