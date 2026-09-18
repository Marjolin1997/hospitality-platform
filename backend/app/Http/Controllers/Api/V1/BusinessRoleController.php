<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SaveBusinessRoleRequest;
use App\Models\Business;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Authorization\ManageBusinessRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BusinessRoleController extends Controller
{
    public function __construct(private readonly ManageBusinessRole $roles) {}

    public function index(Request $request): JsonResponse
    {
        $business = app(Business::class);
        $assignablePermissionKeys = $this->roles->assignablePermissionKeys(
            $business,
            (int) $request->user()->id,
        );

        $roles = Role::query()
            ->where('business_id', $business->getKey())
            ->with(['permissions' => fn ($query) => $query->select('permissions.id', 'key', 'group', 'description')->orderBy('group')->orderBy('key')])
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => [
                'id' => $role->getKey(),
                'name' => $role->name,
                'slug' => $role->slug,
                'is_system' => $role->is_system,
                'member_count' => DB::table('business_user')->where('business_id', $business->getKey())->where('role_id', $role->getKey())->count(),
                'permissions' => $role->permissions->map(fn (Permission $permission) => [
                    'key' => $permission->key,
                    'group' => $permission->group,
                    'description' => $permission->description,
                ])->values(),
            ]);

        $permissions = Permission::query()
            ->whereIn('key', $assignablePermissionKeys)
            ->orderBy('group')
            ->orderBy('key')
            ->get(['key', 'group', 'description']);

        return response()->json([
            'data' => [
                'roles' => $roles,
                'permissions' => $permissions,
            ],
        ]);
    }

    public function store(SaveBusinessRoleRequest $request): JsonResponse
    {
        $role = $this->roles->create(
            app(Business::class),
            $request->validated(),
            (int) $request->user()->id,
        );

        return response()->json(['data' => $this->resource($role)], 201);
    }

    public function update(SaveBusinessRoleRequest $request, string $role): JsonResponse
    {
        $record = $this->roles->update(
            app(Business::class),
            $role,
            $request->validated(),
            (int) $request->user()->id,
        );

        return response()->json(['data' => $this->resource($record)]);
    }

    public function destroy(Request $request, string $role): JsonResponse
    {
        $this->roles->delete(
            app(Business::class),
            $role,
            (int) $request->user()->id,
        );

        return response()->json(['message' => 'Custom role deleted.']);
    }

    private function resource(Role $role): array
    {
        return [
            'id' => $role->getKey(),
            'name' => $role->name,
            'slug' => $role->slug,
            'is_system' => $role->is_system,
            'member_count' => DB::table('business_user')->where('business_id', $role->business_id)->where('role_id', $role->getKey())->count(),
            'permissions' => $role->permissions->map(fn (Permission $permission) => [
                'key' => $permission->key,
                'group' => $permission->group,
                'description' => $permission->description,
            ])->values(),
        ];
    }
}
