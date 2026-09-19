<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $now = now();

        foreach ([
            ['key' => 'venue.manage', 'group' => 'venue', 'description' => 'venue manage'],
            ['key' => 'cash_registers.manage', 'group' => 'cash', 'description' => 'cash registers manage'],
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
            ->whereIn('key', ['venue.manage', 'cash_registers.manage'])
            ->pluck('id');

        $roleIds = DB::table('roles')
            ->where('is_system', true)
            ->whereIn('slug', ['owner', 'manager'])
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('permission_role')->updateOrInsert([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('key', ['venue.manage', 'cash_registers.manage'])
            ->pluck('id');

        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
