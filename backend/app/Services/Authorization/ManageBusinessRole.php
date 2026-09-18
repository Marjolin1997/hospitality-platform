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
    public function create(Business $business, array $data, int $performedByUserId): Role
    {
        return DB::transaction(function () use ($business, $data, $performedByUserId): Role {
            [$permissionIds, $permissionKeys] = $this->resolvePermissions($data['permissions']);

            $role = Role::query()->create([
                'business_id' => $business->getKey(),
                'name' => trim($data['name']),
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

            $nextName = trim($data['name']);

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
            $role = Role::query()
                ->where('business_id', $business->getKey())
                ->whereKey($roleId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertCustom($role);

            $assigned = DB::table('business_user')
                ->where('business_id', $business->getKey())
                ->where('role_id', $role->getKey())
                ->lockForUpdate()
                ->exists();

            if ($assigned) {
                throw ValidationException::withMessages([
                    'role' => 'Reassign every staff member before deleting this role.',
                ]);
            }

            $previousPermissions = $role->permissions()
                ->orderBy('key')
                ->pluck('key')
                ->all();

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

    private function resolvePermissions(array $requested): array
    {
        $keys = collect($requested)
            ->map(fn ($key) => trim((string) $key))
            ->filter()
            ->unique()
            ->sort()
            ->values();

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
