<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private const DEPENDENCIES = [
        'roles.manage' => ['users.view'],
        'stations.view' => ['products.view'],
        'inventory.receive' => ['inventory.view', 'purchasing.view'],
    ];

    public function up(): void
    {
        $keys = collect(self::DEPENDENCIES)
            ->keys()
            ->merge(collect(self::DEPENDENCIES)->flatten())
            ->unique()
            ->values();

        $permissionIds = DB::table('permissions')
            ->whereIn('key', $keys)
            ->pluck('id', 'key');

        $roles = DB::table('roles')->get(['id']);

        foreach ($roles as $role) {
            $granted = DB::table('permission_role as pr')
                ->join('permissions as p', 'p.id', '=', 'pr.permission_id')
                ->where('pr.role_id', $role->id)
                ->pluck('p.key')
                ->all();

            foreach ($granted as $permission) {
                foreach (self::DEPENDENCIES[$permission] ?? [] as $dependency) {
                    $permissionId = $permissionIds[$dependency] ?? null;
                    if ($permissionId === null) {
                        continue;
                    }

                    DB::table('permission_role')->updateOrInsert([
                        'role_id' => $role->id,
                        'permission_id' => $permissionId,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Additive security/UX compatibility migration. Do not remove permissions
        // that may have become intentionally assigned after deployment.
    }
};
