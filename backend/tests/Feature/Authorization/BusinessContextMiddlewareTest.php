<?php

use App\Models\Business;
use App\Models\Role;
use App\Models\User;
use App\Services\Authorization\ProvisionBusinessRoles;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleTemplateSeeder::class);

    Route::middleware(['auth:sanctum', 'tenant'])
        ->get('/api/test/tenant-context', function () {
            $business = request()->attributes->get('business');

            return response()->json([
                'business_id' => $business?->getKey(),
                'container_business_id' => app(Business::class)->getKey(),
            ]);
    });

    Route::middleware([
        'auth:sanctum',
        'tenant',
        'permission:orders.create',
    ])->get('/api/test/orders-create', fn () => response()->json([
        'allowed' => true,
    ]));

    Route::middleware([
        'auth:sanctum',
        'tenant',
        'permission:payments.collect,cash_sessions.view',
    ])->get('/api/test/cash-read', fn () => response()->json([
        'allowed' => true,
    ]));
});

function createRbacBusiness(string $name): Business
{
    $business = Business::query()->create([
        'name' => $name,
        'currency' => 'EUR',
        'timezone' => 'Europe/Berlin',
        'status' => 'active',
    ]);

    app(ProvisionBusinessRoles::class)->handle($business);

    return $business;
}

function createRbacUser(string $email): User
{
    return User::query()->create([
        'name' => 'RBAC Test User',
        'email' => $email,
        'password' => bcrypt('password'),
    ]);
}

function businessRole(Business $business, string $slug): Role
{
    return Role::query()
        ->where('business_id', $business->getKey())
        ->where('slug', $slug)
        ->firstOrFail();
}

function attachRbacMembership(
    User $user,
    Business $business,
    ?Role $role,
    string $status = 'active'
): void {
    $user->businesses()->attach($business->getKey(), [
        'role_id' => $role?->getKey(),
        'status' => $status,
    ]);
}

test('tenant middleware requires a business context header', function (): void {
    $user = createRbacUser('missing-header@example.test');

    Sanctum::actingAs($user);

    $this->getJson('/api/test/tenant-context')
        ->assertStatus(400)
        ->assertJson([
            'message' => 'Business context is required.',
        ]);
});

test('active member can resolve the requested active business', function (): void {
    $business = createRbacBusiness('Business A');
    $user = createRbacUser('active-member@example.test');

    attachRbacMembership(
        $user,
        $business,
        businessRole($business, 'waiter')
    );

    Sanctum::actingAs($user);

    $this->withHeader('X-Business-Id', $business->getKey())
        ->getJson('/api/test/tenant-context')
        ->assertOk()
        ->assertJson([
            'business_id' => $business->getKey(),
            'container_business_id' => $business->getKey(),
        ]);
});

test('user cannot resolve a business without membership', function (): void {
    $business = createRbacBusiness('Business A');
    $user = createRbacUser('no-membership@example.test');

    Sanctum::actingAs($user);

    $this->withHeader('X-Business-Id', $business->getKey())
        ->getJson('/api/test/tenant-context')
        ->assertNotFound();
});

test('inactive membership cannot resolve business context', function (): void {
    $business = createRbacBusiness('Business A');
    $user = createRbacUser('inactive-member@example.test');

    attachRbacMembership(
        $user,
        $business,
        businessRole($business, 'waiter'),
        'inactive'
    );

    Sanctum::actingAs($user);

    $this->withHeader('X-Business-Id', $business->getKey())
        ->getJson('/api/test/tenant-context')
        ->assertNotFound();
});

test('inactive business cannot be resolved even with active membership', function (): void {
    $business = createRbacBusiness('Business A');
    $user = createRbacUser('inactive-business@example.test');

    attachRbacMembership(
        $user,
        $business,
        businessRole($business, 'waiter')
    );

    $business->update(['status' => 'inactive']);

    Sanctum::actingAs($user);

    $this->withHeader('X-Business-Id', $business->getKey())
        ->getJson('/api/test/tenant-context')
        ->assertNotFound();
});

test('permission middleware allows a business role with the requested permission', function (): void {
    $business = createRbacBusiness('Business A');
    $user = createRbacUser('allowed@example.test');

    attachRbacMembership(
        $user,
        $business,
        businessRole($business, 'waiter')
    );

    Sanctum::actingAs($user);

    $this->withHeader('X-Business-Id', $business->getKey())
        ->getJson('/api/test/orders-create')
        ->assertOk()
        ->assertJson(['allowed' => true]);
});

test('permission middleware denies a business role without the requested permission', function (): void {
    $business = createRbacBusiness('Business A');
    $user = createRbacUser('denied@example.test');

    attachRbacMembership(
        $user,
        $business,
        businessRole($business, 'bartender')
    );

    Sanctum::actingAs($user);

    $this->withHeader('X-Business-Id', $business->getKey())
        ->getJson('/api/test/orders-create')
        ->assertStatus(403)
        ->assertJson([
            'message' => 'You do not have permission to perform this action.',
        ]);
});

test('permission middleware accepts any matching permission from a shared read gate', function (): void {
    $business = createRbacBusiness('Any Permission Business');
    $user = createRbacUser('cash-view-only@example.test');

    $role = Role::query()->create([
        'business_id' => $business->getKey(),
        'name' => 'Cash Viewer',
        'slug' => 'cash-viewer',
        'is_system' => false,
    ]);

    $permissionId = DB::table('permissions')->where('key', 'cash_sessions.view')->value('id');
    DB::table('permission_role')->insert([
        'role_id' => $role->getKey(),
        'permission_id' => $permissionId,
    ]);

    attachRbacMembership($user, $business, $role);
    Sanctum::actingAs($user);

    $this->withHeader('X-Business-Id', $business->getKey())
        ->getJson('/api/test/cash-read')
        ->assertOk()
        ->assertJson(['allowed' => true]);

    $this->withHeader('X-Business-Id', $business->getKey())
        ->getJson('/api/test/orders-create')
        ->assertForbidden();
});

test('permission middleware denies shared read gate when none of the permissions match', function (): void {
    $business = createRbacBusiness('Any Permission Deny');
    $user = createRbacUser('cash-read-denied@example.test');

    attachRbacMembership(
        $user,
        $business,
        businessRole($business, 'bartender')
    );

    Sanctum::actingAs($user);

    $this->withHeader('X-Business-Id', $business->getKey())
        ->getJson('/api/test/cash-read')
        ->assertForbidden();
});

test('membership in one business cannot authorize another business', function (): void {
    $businessA = createRbacBusiness('Business A');
    $businessB = createRbacBusiness('Business B');
    $user = createRbacUser('cross-tenant@example.test');

    attachRbacMembership(
        $user,
        $businessA,
        businessRole($businessA, 'owner')
    );

    Sanctum::actingAs($user);

    $this->withHeader('X-Business-Id', $businessB->getKey())
        ->getJson('/api/test/orders-create')
        ->assertNotFound();
});

test('role belonging to another business cannot satisfy permission middleware', function (): void {
    $businessA = createRbacBusiness('Business A');
    $businessB = createRbacBusiness('Business B');
    $user = createRbacUser('cross-role@example.test');

    attachRbacMembership(
        $user,
        $businessA,
        businessRole($businessB, 'owner')
    );

    Sanctum::actingAs($user);

    $this->withHeader('X-Business-Id', $businessA->getKey())
        ->getJson('/api/test/orders-create')
        ->assertStatus(403);
});
