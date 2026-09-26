<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $now = now();

        foreach ([
            ['key' => 'stations.view', 'group' => 'settings', 'description' => 'stations view'],
            ['key' => 'stations.manage', 'group' => 'settings', 'description' => 'stations manage'],
        ] as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['key' => $permission['key']],
                [
                    'group' => $permission['group'],
                    'description' => $permission['description'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('key', ['stations.view', 'stations.manage'])
            ->pluck('id', 'key');

        $roles = DB::table('roles')
            ->where('is_system', true)
            ->whereIn('slug', ['owner', 'manager', 'bartender'])
            ->get(['id', 'slug']);

        foreach ($roles as $role) {
            $keys = $role->slug === 'bartender'
                ? ['stations.view']
                : ['stations.view', 'stations.manage'];

            foreach ($keys as $key) {
                DB::table('permission_role')->updateOrInsert([
                    'role_id' => $role->id,
                    'permission_id' => $permissionIds[$key],
                ]);
            }
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('key', ['stations.view', 'stations.manage'])
            ->pluck('id');

        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
