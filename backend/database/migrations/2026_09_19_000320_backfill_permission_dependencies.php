<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private const DEPENDENCIES = [
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
        'stations.manage' => ['stations.view'],
        'purchasing.manage' => ['purchasing.view'],
        'inventory.receive' => ['inventory.view'],
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
    ];

    public function up(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('key', collect(self::DEPENDENCIES)->flatten()->merge(array_keys(self::DEPENDENCIES))->unique()->all())
            ->pluck('id', 'key');

        $roles = DB::table('roles')->get(['id']);

        foreach ($roles as $role) {
            $keys = DB::table('permission_role as pr')
                ->join('permissions as p', 'p.id', '=', 'pr.permission_id')
                ->where('pr.role_id', $role->id)
                ->pluck('p.key')
                ->all();

            foreach ($keys as $key) {
                foreach (self::DEPENDENCIES[$key] ?? [] as $dependency) {
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
        // Additive safety migration: prerequisites may have been assigned intentionally
        // after deployment, so rollback must not remove permissions from live roles.
    }
};
