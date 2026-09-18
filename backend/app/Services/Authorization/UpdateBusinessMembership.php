<?php

namespace App\Services\Authorization;

use App\Models\Business;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class UpdateBusinessMembership
{
    public function execute(
        Business $business,
        int $userId,
        string $roleId,
        string $status,
        int $performedByUserId,
    ): void {
        DB::transaction(function () use ($business, $userId, $roleId, $status, $performedByUserId): void {
            $targetRole = DB::table('roles')
                ->where('business_id', $business->getKey())
                ->where('id', $roleId)
                ->lockForUpdate()
                ->select('id', 'name', 'slug')
                ->first();

            if (! $targetRole) {
                throw ValidationException::withMessages([
                    'role_id' => 'The selected role does not belong to this business.',
                ]);
            }

            $activeOwnerIds = DB::table('business_user as bu')
                ->join('roles as r', 'r.id', '=', 'bu.role_id')
                ->where('bu.business_id', $business->getKey())
                ->where('bu.status', 'active')
                ->where('r.business_id', $business->getKey())
                ->where('r.slug', 'owner')
                ->orderBy('bu.user_id')
                ->lockForUpdate()
                ->pluck('bu.user_id');

            $membership = DB::table('business_user as bu')
                ->leftJoin('roles as r', 'r.id', '=', 'bu.role_id')
                ->where('bu.business_id', $business->getKey())
                ->where('bu.user_id', $userId)
                ->lockForUpdate()
                ->select(
                    'bu.user_id',
                    'bu.status',
                    'bu.role_id',
                    'r.name as role_name',
                    'r.slug as role_slug',
                    'r.business_id as role_business_id',
                )
                ->first();

            if (! $membership) {
                abort(404);
            }

            $removesActiveOwner = $this->isActiveBusinessOwner($membership, $business)
                && ($status !== 'active' || $targetRole->slug !== 'owner');

            if ($removesActiveOwner && $activeOwnerIds->count() <= 1) {
                throw ValidationException::withMessages([
                    'user' => 'At least one active owner must remain assigned to the business.',
                ]);
            }

            $roleChanged = (string) $membership->role_id !== (string) $targetRole->id;
            $statusChanged = (string) $membership->status !== $status;

            if (! $roleChanged && ! $statusChanged) {
                return;
            }

            DB::table('business_user')
                ->where('business_id', $business->getKey())
                ->where('user_id', $userId)
                ->update([
                    'role_id' => $targetRole->id,
                    'status' => $status,
                    'updated_at' => now(),
                ]);

            DB::table('business_membership_audits')->insert([
                'id' => (string) Str::ulid(),
                'business_id' => $business->getKey(),
                'target_user_id' => $userId,
                'performed_by_user_id' => $performedByUserId,
                'previous_role_id' => $membership->role_id,
                'previous_role_name' => $membership->role_name,
                'previous_role_slug' => $membership->role_slug,
                'previous_status' => $membership->status,
                'new_role_id' => $targetRole->id,
                'new_role_name' => $targetRole->name,
                'new_role_slug' => $targetRole->slug,
                'new_status' => $status,
                'action' => match (true) {
                    $roleChanged && $statusChanged => 'role_and_status_changed',
                    $roleChanged => 'role_changed',
                    default => 'status_changed',
                },
                'performed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, 3);
    }

    private function isActiveBusinessOwner(object $membership, Business $business): bool
    {
        return $membership->status === 'active'
            && $membership->role_slug === 'owner'
            && (string) $membership->role_business_id === (string) $business->getKey();
    }
}
