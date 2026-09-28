<?php

namespace App\Services\Authorization;

use App\Models\Business;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ManageBusinessRole
{
    private const PERMISSION_DEPENDENCIES = [
        'orders.view' => ['products.view'],
        'orders.create' => ['orders.view'],
        'orders.update' => ['orders.view'],
        'orders.send_to_station' => ['orders.view'],
        'orders.prepare' => ['orders.view'],
        'orders.cancel' => ['orders.view'],
        'orders.apply_discount' => ['orders.view'],
        'orders.override_price' => ['orders.view'],
        'orders.split' => ['orders.view'],
        'orders.merge' => ['orders.view'],
        'payments.collect' => ['orders.view'],
        'payments.refund' => ['orders.view'],
        'products.manage' => ['products.view'],
        'stations.view' => ['products.view'],
        'stations.manage' => ['stations.view'],
        'purchasing.manage' => ['purchasing.view'],
        'inventory.receive' => ['inventory.view', 'purchasing.view'],
        'inventory.transfer' => ['inventory.view'],
        'inventory.adjust' => ['inventory.view'],
        'expenses.create' => ['finance.view'],
        'expenses.approve' => ['finance.view'],
        'expenses.view' => ['finance.view'],
        'invoices.issue' => ['invoices.view'],
        'invoices.correct' => ['invoices.view'],
        'fiscalization.manage' => ['fiscalization.view'],
        'fiscalization.activate_production' => ['fiscalization.view'],
        'fiscalization.issue' => ['invoices.view'],
        'fiscalization.retry' => ['invoices.view'],
        'users.manage' => ['users.view'],
        'roles.manage' => ['users.view'],
    ];

    public function __construct(private readonly RoleDelegationPolicy $delegation) {}

    public function create(Business $business, array $data, int $performedByUserId): Role
    {
        return DB::transaction(function () use ($business, $data, $performedByUserId): Role {
            DB::table('businesses')->where('id', $business->getKey())->lockForUpdate()->first();
            [$permissionIds, $permissionKeys] = $this->resolvePermissions($data['permissions']);
            $this->delegation->assertPermissionsDelegable($business, $performedByUserId, $permissionKeys, true);
            $name = trim($data['name']);
            $this->assertUniqueName($business, $name);

            $role = Role::query()->create([
                'business_id' => $business->getKey(),
                'name' => $name,
                'slug' => $this->customSlug($data['name']),
                'is_system' => false,
            ]);

            $role->permissions()->sync($permissionIds);

            $this->audit(
                $business,
                $role,
                $performedByUserId,
                'created',
                null,
                $role->name,
                null,
                $permissionKeys,
            );

            return $role->load('permissions:id,key,group,description')->loadCount('users');
        }, 3);
    }

    public function update(Business $business, string $roleId, array $data, int $performedByUserId): Role
    {
        return DB::transaction(function () use ($business, $roleId, $data, $performedByUserId): Role {
            DB::table('businesses')->where('id', $business->getKey())->lockForUpdate()->first();

            $role = Role::query()
                ->where('business_id', $business->getKey())
                ->whereKey($roleId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertCustom($role);
            [$permissionIds, $permissionKeys] = $this->resolvePermissions($data['permissions']);

            $previousName = $role->name;
            $previousPermissions = $role->permissions()
                ->orderBy('key')
                ->pluck('key')
                ->all();

            $this->delegation->assertExistingRoleManageable($business, $performedByUserId, $previousPermissions, true);
            $this->delegation->assertPermissionsDelegable($business, $performedByUserId, $permissionKeys, true);

            $nextName = trim($data['name']);
            $this->assertUniqueName($business, $nextName, $role->getKey());

            if ($previousName === $nextName && $previousPermissions === $permissionKeys) {
                return $role->load('permissions:id,key,group,description')->loadCount('users');
            }

            $role->update(['name' => $nextName]);
            $role->permissions()->sync($permissionIds);

            $this->audit(
                $business,
                $role,
                $performedByUserId,
                'updated',
                $previousName,
                $nextName,
                $previousPermissions,
                $permissionKeys,
            );

            return $role->fresh()->load('permissions:id,key,group,description')->loadCount('users');
        }, 3);
    }

    public function delete(Business $business, string $roleId, int $performedByUserId): void
    {
        DB::transaction(function () use ($business, $roleId, $performedByUserId): void {
            DB::table('businesses')->where('id', $business->getKey())->lockForUpdate()->first();

            $role = Role::query()
                ->where('business_id', $business->getKey())
                ->whereKey($roleId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertCustom($role);

            $assignedMemberships = DB::table('business_user')
                ->where('business_id', $business->getKey())
                ->where('role_id', $role->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id');

            if ($assignedMemberships->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'role' => 'Reassign every staff member before deleting this role.',
                ]);
            }

            $hasPendingInvitations = DB::table('staff_invitations')
                ->where('business_id', $business->getKey())
                ->where('role_id', $role->getKey())
                ->where('status', 'pending')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->exists();

            if ($hasPendingInvitations) {
                throw ValidationException::withMessages([
                    'role' => 'Revoke or let pending staff invitations expire before deleting this role.',
                ]);
            }

            $previousPermissions = $role->permissions()
                ->orderBy('key')
                ->pluck('key')
                ->all();

            $this->delegation->assertExistingRoleManageable($business, $performedByUserId, $previousPermissions, true);

            $this->audit(
                $business,
                $role,
                $performedByUserId,
                'deleted',
                $role->name,
                null,
                $previousPermissions,
                null,
            );

            $role->delete();
        }, 3);
    }

    public function assignablePermissionKeys(Business $business, int $userId): array
    {
        return $this->delegation->actorPermissionKeys($business, $userId);
    }

    private function assertUniqueName(Business $business, string $name, ?string $exceptRoleId = null): void
    {
        $query = DB::table('roles')
            ->where('business_id', $business->getKey())
            ->whereRaw('LOWER(name) = ?', [Str::lower($name)]);

        if ($exceptRoleId !== null) {
            $query->where('id', '!=', $exceptRoleId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => 'A role with this name already exists in this business.',
            ]);
        }
    }

    private function resolvePermissions(array $requested): array
    {
        $keys = collect($requested)
            ->map(fn ($key) => trim((string) $key))
            ->filter()
            ->unique()
            ->values();

        $expanded = $keys->all();
        do {
            $before = count($expanded);
            foreach ($expanded as $key) {
                foreach (self::PERMISSION_DEPENDENCIES[$key] ?? [] as $dependency) {
                    if (! in_array($dependency, $expanded, true)) {
                        $expanded[] = $dependency;
                    }
                }
            }
        } while (count($expanded) !== $before);

        $keys = collect($expanded)->unique()->sort()->values();

        $permissions = Permission::query()
            ->whereIn('key', $keys)
            ->orderBy('key')
            ->get(['id', 'key']);

        if ($permissions->count() !== $keys->count()) {
            throw ValidationException::withMessages([
                'permissions' => 'One or more permissions are invalid.',
            ]);
        }

        return [$permissions->pluck('id')->all(), $permissions->pluck('key')->all()];
    }

    private function assertCustom(Role $role): void
    {
        if ($role->is_system) {
            throw ValidationException::withMessages([
                'role' => 'System role templates are read-only. Create a custom role instead.',
            ]);
        }
    }

    private function customSlug(string $name): string
    {
        $base = Str::limit(Str::slug($name), 80, '');
        $base = $base !== '' ? $base : 'role';

        return 'custom-'.$base.'-'.Str::lower(substr((string) Str::ulid(), -8));
    }

    private function audit(
        Business $business,
        Role $role,
        int $performedByUserId,
        string $action,
        ?string $previousName,
        ?string $newName,
        ?array $previousPermissions,
        ?array $newPermissions,
    ): void {
        DB::table('business_role_audits')->insert([
            'id' => (string) Str::ulid(),
            'business_id' => $business->getKey(),
            'role_id' => $role->getKey(),
            'performed_by_user_id' => $performedByUserId,
            'role_slug' => $role->slug,
            'previous_name' => $previousName,
            'new_name' => $newName,
            'previous_permissions' => $previousPermissions === null ? null : json_encode(array_values($previousPermissions), JSON_THROW_ON_ERROR),
            'new_permissions' => $newPermissions === null ? null : json_encode(array_values($newPermissions), JSON_THROW_ON_ERROR),
            'action' => $action,
            'performed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
