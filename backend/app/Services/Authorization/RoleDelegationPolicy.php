<?php

namespace App\Services\Authorization;

use App\Models\Business;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RoleDelegationPolicy
{
    public function actorPermissionKeys(Business $business, int $userId, bool $lock = false): array
    {
        $membershipQuery = DB::table('business_user')
            ->where('business_id', $business->getKey())
            ->where('user_id', $userId)
            ->where('status', 'active');

        if ($lock) {
            $membershipQuery->lockForUpdate();
        }

        $membership = $membershipQuery->first();

        if (! $membership || ! $membership->role_id) {
            return [];
        }

        $roleQuery = DB::table('roles')
            ->where('business_id', $business->getKey())
            ->where('id', $membership->role_id);

        if ($lock) {
            $roleQuery->lockForUpdate();
        }

        if (! $roleQuery->first()) {
            return [];
        }

        return DB::table('permission_role as pr')
            ->join('permissions as p', 'p.id', '=', 'pr.permission_id')
            ->where('pr.role_id', $membership->role_id)
            ->orderBy('p.key')
            ->pluck('p.key')
            ->all();
    }

    public function rolePermissionKeys(Business $business, string $roleId, bool $lock = false): array
    {
        $roleQuery = DB::table('roles')
            ->where('business_id', $business->getKey())
            ->where('id', $roleId);

        if ($lock) {
            $roleQuery->lockForUpdate();
        }

        $role = $roleQuery->first();

        if (! $role) {
            throw ValidationException::withMessages([
                'role_id' => 'The selected role does not belong to this business.',
            ]);
        }

        return DB::table('permission_role as pr')
            ->join('permissions as p', 'p.id', '=', 'pr.permission_id')
            ->where('pr.role_id', $roleId)
            ->orderBy('p.key')
            ->pluck('p.key')
            ->all();
    }

    public function assertPermissionsDelegable(
        Business $business,
        int $actorUserId,
        array $requestedPermissions,
        bool $lock = false,
    ): void {
        $actorPermissions = $this->actorPermissionKeys($business, $actorUserId, $lock);

        if (array_diff($requestedPermissions, $actorPermissions) !== []) {
            throw ValidationException::withMessages([
                'permissions' => 'You cannot grant permissions that your own business role does not have.',
            ]);
        }
    }

    public function assertRoleDelegable(
        Business $business,
        int $actorUserId,
        string $roleId,
        bool $lock = false,
    ): array {
        $rolePermissions = $this->rolePermissionKeys($business, $roleId, $lock);
        $actorPermissions = $this->actorPermissionKeys($business, $actorUserId, $lock);

        if (array_diff($rolePermissions, $actorPermissions) !== []) {
            throw ValidationException::withMessages([
                'role_id' => 'You cannot assign a role that contains permissions above your own access level.',
            ]);
        }

        return $rolePermissions;
    }

    public function assertExistingRoleManageable(
        Business $business,
        int $actorUserId,
        array $rolePermissions,
        bool $lock = false,
    ): void {
        $actorPermissions = $this->actorPermissionKeys($business, $actorUserId, $lock);

        if (array_diff($rolePermissions, $actorPermissions) !== []) {
            throw ValidationException::withMessages([
                'role' => 'You cannot modify a role that contains permissions above your own access level.',
            ]);
        }
    }

    public function assignableRoleIds(Business $business, int $actorUserId): array
    {
        $actorPermissions = $this->actorPermissionKeys($business, $actorUserId);

        return DB::table('roles')
            ->where('business_id', $business->getKey())
            ->orderBy('name')
            ->get(['id'])
            ->filter(function (object $role) use ($actorPermissions): bool {
                $rolePermissions = DB::table('permission_role as pr')
                    ->join('permissions as p', 'p.id', '=', 'pr.permission_id')
                    ->where('pr.role_id', $role->id)
                    ->pluck('p.key')
                    ->all();

                return array_diff($rolePermissions, $actorPermissions) === [];
            })
            ->pluck('id')
            ->all();
    }
}
