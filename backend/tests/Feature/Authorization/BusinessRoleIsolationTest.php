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

function attachMembership(
    User $user,
    Business $business,
    ?Role $role,
    string $status = 'active',
): void {
    $user->businesses()->attach($business->getKey(), [
        'role_id' => $role?->getKey(),
        'status' => $status,
    ]);
}

test('business role templates are provisioned independently and idempotently', function (): void {
    $businessA = createBusiness('Business A');
    $businessB = createBusiness('Business B');

    $provisioner = app(ProvisionBusinessRoles::class);

    $rolesA = $provisioner->handle($businessA);
    $rolesB = $provisioner->handle($businessB);

    expect($rolesA)->toHaveCount(7)
        ->and($rolesB)->toHaveCount(7);

    $ownerA = Role::query()
        ->where('business_id', $businessA->getKey())
        ->where('slug', 'owner')
        ->firstOrFail();

    $ownerB = Role::query()
        ->where('business_id', $businessB->getKey())
        ->where('slug', 'owner')
        ->firstOrFail();

    expect($ownerA->business_id)->toBe($businessA->getKey())
        ->and($ownerB->business_id)->toBe($businessB->getKey())
        ->and($ownerA->getKey())->not->toBe($ownerB->getKey())
        ->and($ownerA->permissions()->count())->toBe(Permission::query()->count())
        ->and($ownerB->permissions()->count())->toBe(Permission::query()->count());

    $provisioner->handle($businessA);

    expect(
        Role::query()
            ->where('business_id', $businessA->getKey())
            ->count()
    )->toBe(7);

    expect(
        Role::query()
            ->where('business_id', $businessA->getKey())
            ->distinct()
            ->count('slug')
    )->toBe(7);
});

test('user receives permissions only from the role assigned inside the requested business', function (): void {
    $businessA = createBusiness('Business A');
    $businessB = createBusiness('Business B');

    $provisioner = app(ProvisionBusinessRoles::class);

    $provisioner->handle($businessA);
    $provisioner->handle($businessB);

    $ownerA = Role::query()
        ->where('business_id', $businessA->getKey())
        ->where('slug', 'owner')
        ->firstOrFail();

    $user = createUserForRbac('owner-a@example.test');

    attachMembership($user, $businessA, $ownerA);

    expect(
        $user->hasPermissionInBusiness($businessA, 'orders.create')
    )->toBeTrue();

    expect(
        $user->hasPermissionInBusiness($businessB, 'orders.create')
    )->toBeFalse();
});

test('global role template cannot grant a business permission directly', function (): void {
    $business = createBusiness('Business A');

    $globalOwner = Role::query()
        ->whereNull('business_id')
        ->where('slug', 'owner')
        ->firstOrFail();

    $user = createUserForRbac('global-owner@example.test');

    attachMembership($user, $business, $globalOwner);

    expect(
        $user->hasPermissionInBusiness($business, 'orders.create')
    )->toBeFalse();
});

test('role from another business cannot grant permission', function (): void {
    $businessA = createBusiness('Business A');
    $businessB = createBusiness('Business B');

    $provisioner = app(ProvisionBusinessRoles::class);

    $provisioner->handle($businessA);
    $provisioner->handle($businessB);

    $ownerB = Role::query()
        ->where('business_id', $businessB->getKey())
        ->where('slug', 'owner')
        ->firstOrFail();

    $user = createUserForRbac('cross-tenant@example.test');

    attachMembership($user, $businessA, $ownerB);

    expect(
        $user->hasPermissionInBusiness($businessA, 'orders.create')
    )->toBeFalse();
});

test('inactive membership grants no permission', function (): void {
    $business = createBusiness('Business A');

    app(ProvisionBusinessRoles::class)->handle($business);

    $owner = Role::query()
        ->where('business_id', $business->getKey())
        ->where('slug', 'owner')
        ->firstOrFail();

    $user = createUserForRbac('inactive@example.test');

    attachMembership($user, $business, $owner, 'inactive');

    expect(
        $user->hasPermissionInBusiness($business, 'orders.create')
    )->toBeFalse();
});

test('membership without a role grants no permission', function (): void {
    $business = createBusiness('Business A');

    $user = createUserForRbac('no-role@example.test');

    attachMembership($user, $business, null);

    expect(
        $user->hasPermissionInBusiness($business, 'orders.create')
    )->toBeFalse();
});

test('role without requested permission grants no permission', function (): void {
    $business = createBusiness('Business A');

    app(ProvisionBusinessRoles::class)->handle($business);

    $bartender = Role::query()
        ->where('business_id', $business->getKey())
        ->where('slug', 'bartender')
        ->firstOrFail();

    $user = createUserForRbac('bartender@example.test');

    attachMembership($user, $business, $bartender);

    expect(
        $user->hasPermissionInBusiness($business, 'orders.create')
    )->toBeFalse();
});
