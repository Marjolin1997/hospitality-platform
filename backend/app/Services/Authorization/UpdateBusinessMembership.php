<?php

namespace App\Services\Authorization;

use App\Models\Business;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateBusinessMembership
{
    public function execute(Business $business, int $userId, string $roleId, string $status): void
    {
        DB::transaction(function () use ($business, $userId, $roleId, $status): void {
            $targetRole = DB::table('roles')
                ->where('business_id', $business->getKey())
                ->where('id', $roleId)
                ->select('id', 'slug')
                ->first();

            if (! $targetRole) {
                throw ValidationException::withMessages([
                    'role_id' => 'The selected role does not belong to this business.',
                ]);
            }

            $membership = $this->membership($business, $userId, false);

            if (! $membership) {
                abort(404);
            }

            $removesActiveOwner = $membership->status === 'active'
                && $membership->role_slug === 'owner'
                && ($status !== 'active' || $targetRole->slug !== 'owner');

            if ($removesActiveOwner) {
                $activeOwnerIds = DB::table('business_user as bu')
                    ->join('roles as r', 'r.id', '=', 'bu.role_id')
                    ->where('bu.business_id', $business->getKey())
                    ->where('bu.status', 'active')
                    ->where('r.business_id', $business->getKey())
                    ->where('r.slug', 'owner')
                    ->orderBy('bu.user_id')
                    ->lockForUpdate()
                    ->pluck('bu.user_id');

                $membership = $this->membership($business, $userId, true);

                $stillRemovesActiveOwner = $membership
                    && $membership->status === 'active'
                    && $membership->role_slug === 'owner'
                    && ($status !== 'active' || $targetRole->slug !== 'owner');

                if ($stillRemovesActiveOwner && $activeOwnerIds->count() <= 1) {
                    throw ValidationException::withMessages([
                        'user' => 'At least one active owner must remain assigned to the business.',
                    ]);
                }
            } else {
                $this->lockMembership($business, $userId);
            }

            DB::table('business_user')
                ->where('business_id', $business->getKey())
                ->where('user_id', $userId)
                ->update([
                    'role_id' => $roleId,
                    'status' => $status,
                    'updated_at' => now(),
                ]);
        }, 3);
    }

    private function membership(Business $business, int $userId, bool $lock): ?object
    {
        $query = DB::table('business_user as bu')
            ->leftJoin('roles as r', 'r.id', '=', 'bu.role_id')
            ->where('bu.business_id', $business->getKey())
            ->where('bu.user_id', $userId)
            ->select('bu.user_id', 'bu.status', 'bu.role_id', 'r.slug as role_slug');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function lockMembership(Business $business, int $userId): void
    {
        $exists = DB::table('business_user')
            ->where('business_id', $business->getKey())
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->exists();

        abort_unless($exists, 404);
    }
}
