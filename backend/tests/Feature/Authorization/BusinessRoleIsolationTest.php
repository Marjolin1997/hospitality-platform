<?php

use App\Models\Business;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Authorization\ProvisionBusinessRoles;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleTemplateSeeder::class);
});

function createBusiness(string $name): Business
{
    return Business::query()->create([
        'name' => $name,
        'legal_name' => $name.' LLC',
        'currency' => 'ALL',
        'timezone' => 'Europe/Tirane',
        'status' => 'active',
    ]);
}

function createUserForRbac(string $email): User
{
    return User::query()->create([
        'name' => 'RBAC Test User',
        'email' => $email,
        'password' => 'test-password',
    ]);
}

function attachMembership(User $user, Business $business, ?Role $role, string $status = 'active'): void
{
    $user->businesses()->attach($business->getKey(), [
        'role_id' => $role?->getKey(),
        'status' => $status,
    ]);
}

function rbacBusinessRole(Business $business, string $slug): Role
{
    return Role::query()->where('business_id', $business->getKey())->where('slug', $slug)->firstOrFail();
}

function rbacRolePermissionKeys(Role $role): array
{
    return $role->permissions()->orderBy('key')->pluck('key')->all();
}

test('business role templates are provisioned independently and idempotently', function (): void {
    $businessA = createBusiness('Business A');
    $businessB = createBusiness('Business B');
    $provisioner = app(ProvisionBusinessRoles::class);
    $rolesA = $provisioner->handle($businessA);
    $rolesB = $provisioner->handle($businessB);
    expect($rolesA)->toHaveCount(7)->and($rolesB)->toHaveCount(7);
    $ownerA = rbacBusinessRole($businessA, 'owner');
    $ownerB = rbacBusinessRole($businessB, 'owner');
    expect($ownerA->business_id)->toBe($businessA->getKey())
        ->and($ownerB->business_id)->toBe($businessB->getKey())
        ->and($ownerA->getKey())->not->toBe($ownerB->getKey())
        ->and($ownerA->permissions()->count())->toBe(Permission::query()->count())
        ->and($ownerB->permissions()->count())->toBe(Permission::query()->count());
    $provisioner->handle($businessA);
    expect(Role::query()->where('business_id', $businessA->getKey())->count())->toBe(7)
        ->and(Role::query()->where('business_id', $businessA->getKey())->distinct()->count('slug'))->toBe(7);
});

test('operational role templates enforce least privilege contracts', function (): void {
    $business = createBusiness('Operations RBAC');
    app(ProvisionBusinessRoles::class)->handle($business);

    $owner = rbacBusinessRole($business, 'owner');
    $manager = rbacBusinessRole($business, 'manager');
    $waiter = rbacBusinessRole($business, 'waiter');
    $bartender = rbacBusinessRole($business, 'bartender');
    $cashier = rbacBusinessRole($business, 'cashier');
    $finance = rbacBusinessRole($business, 'finance');

    expect(rbacRolePermissionKeys($owner))->toBe(Permission::query()->orderBy('key')->pluck('key')->all())
        ->and(rbacRolePermissionKeys($manager))->toContain('orders.prepare', 'orders.split', 'orders.merge')
        ->and(rbacRolePermissionKeys($waiter))->toContain('orders.create', 'orders.update', 'orders.send_to_station', 'orders.split', 'orders.merge', 'payments.collect')
        ->and(rbacRolePermissionKeys($waiter))->not->toContain('orders.prepare', 'payments.refund', 'orders.override_price')
        ->and(rbacRolePermissionKeys($bartender))->toContain('orders.view', 'orders.prepare', 'products.view')
        ->and(rbacRolePermissionKeys($bartender))->not->toContain('orders.create', 'orders.split', 'orders.merge', 'payments.collect')
        ->and(rbacRolePermissionKeys($cashier))->toContain('orders.view', 'payments.collect', 'payments.refund', 'invoices.view')
        ->and(rbacRolePermissionKeys($cashier))->not->toContain('orders.prepare', 'orders.split', 'orders.merge', 'expenses.approve', 'invoices.correct')
        ->and(rbacRolePermissionKeys($manager))->toContain('expenses.create', 'expenses.approve', 'invoices.issue', 'invoices.correct', 'fiscalization.view', 'fiscalization.issue', 'fiscalization.retry')
        ->and(rbacRolePermissionKeys($manager))->not->toContain('fiscalization.manage')
        ->and(rbacRolePermissionKeys($finance))->toContain('finance.view', 'expenses.create', 'expenses.approve', 'invoices.issue', 'invoices.correct', 'fiscalization.view', 'fiscalization.issue', 'fiscalization.retry')
        ->and(rbacRolePermissionKeys($finance))->not->toContain('fiscalization.manage')
        ->and(rbacRolePermissionKeys($owner))->toContain('fiscalization.manage', 'fiscalization.issue', 'fiscalization.retry');
});

test('reprovisioning synchronizes newly introduced operational permissions into existing business roles', function (): void {
    $business = createBusiness('Existing Business');
    $provisioner = app(ProvisionBusinessRoles::class);
    $provisioner->handle($business);

    $waiter = rbacBusinessRole($business, 'waiter');
    $bartender = rbacBusinessRole($business, 'bartender');
    $waiter->permissions()->detach(Permission::query()->where('key', 'orders.split')->value('id'));
    $bartender->permissions()->detach(Permission::query()->where('key', 'orders.prepare')->value('id'));

    expect(rbacRolePermissionKeys($waiter))->not->toContain('orders.split')
        ->and(rbacRolePermissionKeys($bartender))->not->toContain('orders.prepare');

    $provisioner->handle($business);

    expect(rbacRolePermissionKeys(rbacBusinessRole($business, 'waiter')))->toContain('orders.split', 'orders.merge')
        ->and(rbacRolePermissionKeys(rbacBusinessRole($business, 'bartender')))->toContain('orders.prepare');
});

test('user receives permissions only from the role assigned inside the requested business', function (): void {
    $businessA = createBusiness('Business A');$businessB = createBusiness('Business B');$provisioner = app(ProvisionBusinessRoles::class);$provisioner->handle($businessA);$provisioner->handle($businessB);$ownerA = rbacBusinessRole($businessA, 'owner');$user = createUserForRbac('owner-a@example.test');attachMembership($user, $businessA, $ownerA);expect($user->hasPermissionInBusiness($businessA, 'orders.create'))->toBeTrue()->and($user->hasPermissionInBusiness($businessB, 'orders.create'))->toBeFalse();
});

test('global role template cannot grant a business permission directly', function (): void {
    $business = createBusiness('Business A');$globalOwner = Role::query()->whereNull('business_id')->where('slug', 'owner')->firstOrFail();$user = createUserForRbac('global-owner@example.test');attachMembership($user, $business, $globalOwner);expect($user->hasPermissionInBusiness($business, 'orders.create'))->toBeFalse();
});

test('role from another business cannot grant permission', function (): void {
    $businessA = createBusiness('Business A');$businessB = createBusiness('Business B');$provisioner = app(ProvisionBusinessRoles::class);$provisioner->handle($businessA);$provisioner->handle($businessB);$ownerB = rbacBusinessRole($businessB, 'owner');$user = createUserForRbac('cross-tenant@example.test');attachMembership($user, $businessA, $ownerB);expect($user->hasPermissionInBusiness($businessA, 'orders.create'))->toBeFalse();
});

test('inactive membership grants no permission', function (): void {
    $business = createBusiness('Business A');app(ProvisionBusinessRoles::class)->handle($business);$owner = rbacBusinessRole($business, 'owner');$user = createUserForRbac('inactive@example.test');attachMembership($user, $business, $owner, 'inactive');expect($user->hasPermissionInBusiness($business, 'orders.create'))->toBeFalse();
});

test('membership without a role grants no permission', function (): void {
    $business = createBusiness('Business A');$user = createUserForRbac('no-role@example.test');attachMembership($user, $business, null);expect($user->hasPermissionInBusiness($business, 'orders.create'))->toBeFalse();
});

test('role without requested permission grants no permission', function (): void {
    $business = createBusiness('Business A');app(ProvisionBusinessRoles::class)->handle($business);$bartender = rbacBusinessRole($business, 'bartender');$user = createUserForRbac('bartender@example.test');attachMembership($user, $business, $bartender);expect($user->hasPermissionInBusiness($business, 'orders.create'))->toBeFalse();
});
