<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $now = now();
        foreach ([
            ['key' => 'purchasing.view', 'group' => 'purchasing', 'description' => 'purchasing view'],
            ['key' => 'purchasing.manage', 'group' => 'purchasing', 'description' => 'purchasing manage'],
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
            ->whereIn('key', ['purchasing.view', 'purchasing.manage'])
            ->pluck('id', 'key');

        $roles = DB::table('roles')
            ->where('is_system', true)
            ->whereIn('slug', ['owner', 'manager', 'inventory'])
            ->get(['id', 'slug']);

        foreach ($roles as $role) {
            $keys = $role->slug === 'inventory'
                ? ['purchasing.view']
                : ['purchasing.view', 'purchasing.manage'];

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
            ->whereIn('key', ['purchasing.view', 'purchasing.manage'])
            ->pluck('id');

        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
