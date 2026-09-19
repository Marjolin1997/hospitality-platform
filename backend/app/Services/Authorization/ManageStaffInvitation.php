<?php

namespace App\Services\Authorization;

use App\Models\Business;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ManageStaffInvitation
{
    public function __construct(private readonly RoleDelegationPolicy $delegation) {}

    public function list(Business $business): array
    {
        $this->expireStale($business);

        $rows = DB::table('staff_invitations as si')
            ->join('users as inviter', 'inviter.id', '=', 'si.invited_by_user_id')
            ->leftJoin('users as accepter', 'accepter.id', '=', 'si.accepted_by_user_id')
            ->where('si.business_id', $business->getKey())
            ->orderByDesc('si.created_at')
            ->limit(100)
            ->get([
                'si.id',
                'si.role_id',
                'si.email',
                'si.role_name_snapshot',
                'si.role_permissions_snapshot',
                'si.status',
                'si.expires_at',
                'si.expired_at',
                'si.reissue_count',
                'si.last_reissued_at',
                'si.accepted_at',
                'si.revoked_at',
                'si.created_at',
                'inviter.name as invited_by_name',
                'accepter.name as accepted_by_name',
            ]);

        $roleIds = $rows->pluck('role_id')->filter()->unique()->values();
        $roles = DB::table('roles')
            ->where('business_id', $business->getKey())
            ->whereIn('id', $roleIds)
            ->get(['id', 'name'])
            ->keyBy('id');

        $rolePermissions = DB::table('permission_role as pr')
            ->join('permissions as p', 'p.id', '=', 'pr.permission_id')
            ->whereIn('pr.role_id', $roleIds)
            ->orderBy('p.key')
            ->get(['pr.role_id', 'p.key'])
            ->groupBy('role_id')
            ->map(fn ($permissions) => $permissions->pluck('key')->values()->all());

        return $rows->map(function (object $row) use ($roles, $rolePermissions): array {
            $snapshot = $this->decodePermissions($row->role_permissions_snapshot);
            $currentPermissions = $row->role_id
                ? ($rolePermissions->get($row->role_id, []))
                : [];

            return [
                'id' => $row->id,
                'email' => $row->email,
                'role_id' => $row->role_id,
                'role_name' => $roles->get($row->role_id)?->name ?? $row->role_name_snapshot,
                'role_name_snapshot' => $row->role_name_snapshot,
                'role_is_current' => $row->role_id !== null
                    && $roles->has($row->role_id)
                    && $currentPermissions === $snapshot,
                'status' => $this->effectiveStatus($row),
                'expires_at' => $row->expires_at,
                'accepted_at' => $row->accepted_at,
                'expired_at' => $row->expired_at,
                'reissue_count' => (int) $row->reissue_count,
                'last_reissued_at' => $row->last_reissued_at,
                'revoked_at' => $row->revoked_at,
                'created_at' => $row->created_at,
                'invited_by_name' => $row->invited_by_name,
                'accepted_by_name' => $row->accepted_by_name,
            ];
        })->all();
    }

    public function create(Business $business, array $data, int $actorUserId): array
    {
        return DB::transaction(function () use ($business, $data, $actorUserId): array {
            $this->lockBusiness($business);

            $email = mb_strtolower(trim($data['email']));
            $roleId = (string) $data['role_id'];

            $role = DB::table('roles')
                ->where('business_id', $business->getKey())
                ->where('id', $roleId)
                ->lockForUpdate()
                ->first(['id', 'name']);

            if (! $role) {
                throw ValidationException::withMessages([
                    'role_id' => 'The selected role does not belong to this business.',
                ]);
            }

            $rolePermissions = $this->delegation->assertRoleDelegable(
                $business,
                $actorUserId,
                $roleId,
                true,
            );

            $existingUserId = DB::table('users')
                ->whereRaw('LOWER(email) = ?', [$email])
                ->value('id');

            if ($existingUserId && DB::table('business_user')
                ->where('business_id', $business->getKey())
                ->where('user_id', $existingUserId)
                ->exists()) {
                throw ValidationException::withMessages([
                    'email' => 'This user already has a membership in the business.',
                ]);
            }

            $hasLiveInvite = DB::table('staff_invitations')
                ->where('business_id', $business->getKey())
                ->where('email', $email)
                ->where('status', 'pending')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->exists();

            if ($hasLiveInvite) {
                throw ValidationException::withMessages([
                    'email' => 'A pending invitation already exists for this email address.',
                ]);
            }

            $token = Str::random(64);
            $id = (string) Str::ulid();
            $expiresAt = now()->addDays((int) $data['expires_in_days']);

            DB::table('staff_invitations')->insert([
                'id' => $id,
                'business_id' => $business->getKey(),
                'role_id' => $roleId,
                'invited_by_user_id' => $actorUserId,
                'email' => $email,
                'role_name_snapshot' => $role->name,
                'role_permissions_snapshot' => json_encode(array_values($rolePermissions), JSON_THROW_ON_ERROR),
                'token_hash' => hash('sha256', $token),
                'status' => 'pending',
                'expires_at' => $expiresAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->auditEvent(
                $business,
                $id,
                $actorUserId,
                'created',
                null,
                'pending',
                [
                    'email' => $email,
                    'role_id' => $roleId,
                    'role_name' => $role->name,
                    'expires_at' => $expiresAt->toISOString(),
                ],
            );

            return [
                'id' => $id,
                'email' => $email,
                'role_id' => $roleId,
                'role_name' => $role->name,
                'status' => 'pending',
                'expires_at' => $expiresAt->toISOString(),
                'invitation_url' => rtrim((string) config('app.frontend_url'), '/').'/join/'.$token,
            ];
        }, 3);
    }

    public function revoke(Business $business, string $invitationId, int $actorUserId): void
    {
        DB::transaction(function () use ($business, $invitationId, $actorUserId): void {
            $this->lockBusiness($business);

            $invitation = DB::table('staff_invitations')
                ->where('business_id', $business->getKey())
                ->where('id', $invitationId)
                ->lockForUpdate()
                ->first();

            abort_unless($invitation, 404);

            if ($invitation->status !== 'pending') {
                throw ValidationException::withMessages([
                    'invitation' => 'Only pending invitations can be revoked.',
                ]);
            }

            if (now()->greaterThanOrEqualTo($invitation->expires_at)) {
                throw ValidationException::withMessages([
                    'invitation' => 'This invitation has already expired.',
                ]);
            }

            DB::table('staff_invitations')
                ->where('id', $invitationId)
                ->update([
                    'status' => 'revoked',
                    'revoked_at' => now(),
                    'revoked_by_user_id' => $actorUserId,
                    'updated_at' => now(),
                ]);

            $this->auditEvent(
                $business,
                $invitationId,
                $actorUserId,
                'revoked',
                'pending',
                'revoked',
            );
        }, 3);
    }

    public function reissue(Business $business, string $invitationId, int $actorUserId, int $expiresInDays): array
    {
        return DB::transaction(function () use ($business, $invitationId, $actorUserId, $expiresInDays): array {
            $this->lockBusiness($business);
            $this->expireStale($business, false);

            $invitation = DB::table('staff_invitations')
                ->where('business_id', $business->getKey())
                ->where('id', $invitationId)
                ->lockForUpdate()
                ->first();

            abort_unless($invitation, 404);

            if (! in_array($invitation->status, ['pending', 'expired'], true)) {
                throw ValidationException::withMessages([
                    'invitation' => 'Only pending or expired invitations can be reissued.',
                ]);
            }

            if (! $invitation->role_id) {
                throw ValidationException::withMessages([
                    'invitation' => 'The invited role no longer exists. Create a new invitation with another role.',
                ]);
            }

            $role = DB::table('roles')
                ->where('business_id', $business->getKey())
                ->where('id', $invitation->role_id)
                ->lockForUpdate()
                ->first(['id', 'name']);

            if (! $role) {
                throw ValidationException::withMessages([
                    'invitation' => 'The invited role no longer exists. Create a new invitation with another role.',
                ]);
            }

            $rolePermissions = $this->delegation->assertRoleDelegable(
                $business,
                $actorUserId,
                (string) $role->id,
                true,
            );

            $existingUserId = DB::table('users')
                ->whereRaw('LOWER(email) = ?', [mb_strtolower($invitation->email)])
                ->value('id');

            if ($existingUserId && DB::table('business_user')
                ->where('business_id', $business->getKey())
                ->where('user_id', $existingUserId)
                ->exists()) {
                throw ValidationException::withMessages([
                    'invitation' => 'This user already has a membership in the business.',
                ]);
            }

            $token = Str::random(64);
            $expiresAt = now()->addDays($expiresInDays);
            $previousStatus = (string) $invitation->status;

            DB::table('staff_invitations')
                ->where('business_id', $business->getKey())
                ->where('id', $invitationId)
                ->update([
                    'invited_by_user_id' => $actorUserId,
                    'role_name_snapshot' => $role->name,
                    'role_permissions_snapshot' => json_encode(array_values($rolePermissions), JSON_THROW_ON_ERROR),
                    'token_hash' => hash('sha256', $token),
                    'status' => 'pending',
                    'expires_at' => $expiresAt,
                    'expired_at' => null,
                    'reissue_count' => DB::raw('reissue_count + 1'),
                    'last_reissued_at' => now(),
                    'last_reissued_by_user_id' => $actorUserId,
                    'updated_at' => now(),
                ]);

            $this->auditEvent(
                $business,
                $invitationId,
                $actorUserId,
                'reissued',
                $previousStatus,
                'pending',
                [
                    'role_id' => $role->id,
                    'role_name' => $role->name,
                    'expires_at' => $expiresAt->toISOString(),
                ],
            );

            return [
                'id' => $invitationId,
                'email' => $invitation->email,
                'role_id' => $role->id,
                'role_name' => $role->name,
                'status' => 'pending',
                'expires_at' => $expiresAt->toISOString(),
                'invitation_url' => rtrim((string) config('app.frontend_url'), '/').'/join/'.$token,
            ];
        }, 3);
    }

    public function events(Business $business, string $invitationId): array
    {
        abort_unless(
            DB::table('staff_invitations')
                ->where('business_id', $business->getKey())
                ->where('id', $invitationId)
                ->exists(),
            404,
        );

        return DB::table('staff_invitation_events as sie')
            ->leftJoin('users as actor', 'actor.id', '=', 'sie.actor_user_id')
            ->where('sie.business_id', $business->getKey())
            ->where('sie.staff_invitation_id', $invitationId)
            ->orderByDesc('sie.occurred_at')
            ->get([
                'sie.id',
                'sie.event',
                'sie.previous_status',
                'sie.new_status',
                'sie.metadata',
                'sie.occurred_at',
                'actor.name as actor_name',
            ])
            ->map(function (object $event): array {
                return [
                    'id' => $event->id,
                    'event' => $event->event,
                    'previous_status' => $event->previous_status,
                    'new_status' => $event->new_status,
                    'metadata' => $event->metadata ? json_decode($event->metadata, true, 512, JSON_THROW_ON_ERROR) : null,
                    'occurred_at' => $event->occurred_at,
                    'actor_name' => $event->actor_name,
                ];
            })
            ->all();
    }

    public function preview(string $token): array
    {
        $tokenHash = hash('sha256', $token);
        $snapshot = DB::table('staff_invitations')->where('token_hash', $tokenHash)->first(['business_id']);
        abort_unless($snapshot, 404);

        $business = Business::query()->findOrFail($snapshot->business_id);
        $this->expireStale($business);

        $invitation = DB::table('staff_invitations as si')
            ->join('businesses as b', 'b.id', '=', 'si.business_id')
            ->where('si.token_hash', $tokenHash)
            ->first([
                'si.email',
                'si.role_name_snapshot',
                'si.status',
                'si.expires_at',
                'b.name as business_name',
                'b.status as business_status',
            ]);

        abort_unless($invitation, 404);

        $status = $this->effectiveStatus($invitation);
        if ($invitation->business_status !== 'active' && $status === 'pending') {
            $status = 'unavailable';
        }

        return [
            'email' => $invitation->email,
            'business_name' => $invitation->business_name,
            'role_name' => $invitation->role_name_snapshot,
            'status' => $status,
            'expires_at' => $invitation->expires_at,
            'existing_user' => DB::table('users')
                ->whereRaw('LOWER(email) = ?', [mb_strtolower($invitation->email)])
                ->exists(),
        ];
    }

    public function accept(string $token, array $data): User
    {
        $tokenHash = hash('sha256', $token);
        $snapshot = DB::table('staff_invitations')
            ->where('token_hash', $tokenHash)
            ->first(['business_id']);

        abort_unless($snapshot, 404);

        try {
            return DB::transaction(function () use ($tokenHash, $data, $snapshot): User {
            $business = Business::query()->whereKey($snapshot->business_id)->lockForUpdate()->firstOrFail();

            if ($business->status !== 'active') {
                throw ValidationException::withMessages([
                    'invitation' => 'This business is not currently accepting invitations.',
                ]);
            }

            $invitation = DB::table('staff_invitations')
                ->where('business_id', $business->getKey())
                ->where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();

            abort_unless($invitation, 404);

            if ($invitation->status !== 'pending') {
                throw ValidationException::withMessages([
                    'invitation' => 'This invitation is no longer available.',
                ]);
            }

            if (now()->greaterThanOrEqualTo($invitation->expires_at)) {
                throw ValidationException::withMessages([
                    'invitation' => 'This invitation has expired. Ask a manager for a new invitation.',
                ]);
            }

            if (! $invitation->role_id) {
                throw ValidationException::withMessages([
                    'invitation' => 'The invited role no longer exists. Ask a manager for a new invitation.',
                ]);
            }

            $role = DB::table('roles')
                ->where('business_id', $business->getKey())
                ->where('id', $invitation->role_id)
                ->lockForUpdate()
                ->first(['id', 'name']);

            if (! $role) {
                throw ValidationException::withMessages([
                    'invitation' => 'The invited role no longer exists. Ask a manager for a new invitation.',
                ]);
            }

            $snapshotPermissions = $this->decodePermissions($invitation->role_permissions_snapshot);
            $currentPermissions = $this->delegation->rolePermissionKeys(
                $business,
                (string) $role->id,
                true,
            );

            if ($snapshotPermissions !== $currentPermissions) {
                throw ValidationException::withMessages([
                    'invitation' => 'The invited role changed after this invitation was issued. Ask a manager to revoke it and send a new invitation.',
                ]);
            }

            $currentInviterPermissions = $this->delegation->actorPermissionKeys(
                $business,
                (int) $invitation->invited_by_user_id,
                true,
            );

            if (array_diff($snapshotPermissions, $currentInviterPermissions) !== []) {
                throw ValidationException::withMessages([
                    'invitation' => 'The inviter no longer has authority to grant this role. Ask an owner or manager for a new invitation.',
                ]);
            }

            $email = mb_strtolower($invitation->email);
            $user = User::query()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->lockForUpdate()
                ->first();

            if ($user) {
                if (! Hash::check((string) $data['password'], $user->password)) {
                    throw ValidationException::withMessages([
                        'password' => 'The password does not match the invited account.',
                    ]);
                }
            } else {
                $name = trim((string) ($data['name'] ?? ''));
                if ($name === '') {
                    throw ValidationException::withMessages([
                        'name' => 'Your name is required to create the invited staff account.',
                    ]);
                }

                $user = User::query()->create([
                    'name' => $name,
                    'email' => $email,
                    'password' => $data['password'],
                ]);
            }

            $existingMembership = DB::table('business_user')
                ->where('business_id', $business->getKey())
                ->where('user_id', $user->getKey())
                ->lockForUpdate()
                ->exists();

            if ($existingMembership) {
                throw ValidationException::withMessages([
                    'invitation' => 'This account already has a membership in the business.',
                ]);
            }

            DB::table('business_user')->insert([
                'business_id' => $business->getKey(),
                'user_id' => $user->getKey(),
                'role_id' => $role->id,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('business_membership_audits')->insert([
                'id' => (string) Str::ulid(),
                'business_id' => $business->getKey(),
                'target_user_id' => $user->getKey(),
                'performed_by_user_id' => $user->getKey(),
                'previous_role_id' => null,
                'previous_role_name' => null,
                'previous_role_slug' => null,
                'previous_status' => 'none',
                'new_role_id' => $role->id,
                'new_role_name' => $role->name,
                'new_role_slug' => DB::table('roles')->where('id', $role->id)->value('slug'),
                'new_status' => 'active',
                'action' => 'invitation_accepted',
                'performed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('staff_invitations')
                ->where('id', $invitation->id)
                ->update([
                    'status' => 'accepted',
                    'accepted_at' => now(),
                    'accepted_by_user_id' => $user->getKey(),
                    'updated_at' => now(),
                ]);

            $this->auditEvent(
                $business,
                (string) $invitation->id,
                (int) $user->getKey(),
                'accepted',
                'pending',
                'accepted',
            );

                return $user->refresh();
            }, 3);
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') {
                throw ValidationException::withMessages([
                    'invitation' => 'The account or business membership changed while this invitation was being accepted. Retry with the invited account credentials.',
                ]);
            }

            throw $exception;
        }
    }

    private function expireStale(Business $business, bool $lockBusiness = true): void
    {
        DB::transaction(function () use ($business, $lockBusiness): void {
            if ($lockBusiness) {
                $this->lockBusiness($business);
            }

            $expired = DB::table('staff_invitations')
                ->where('business_id', $business->getKey())
                ->where('status', 'pending')
                ->where('expires_at', '<=', now())
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);

            foreach ($expired as $invitation) {
                DB::table('staff_invitations')
                    ->where('business_id', $business->getKey())
                    ->where('id', $invitation->id)
                    ->where('status', 'pending')
                    ->update([
                        'status' => 'expired',
                        'expired_at' => now(),
                        'updated_at' => now(),
                    ]);

                $this->auditEvent(
                    $business,
                    (string) $invitation->id,
                    null,
                    'expired',
                    'pending',
                    'expired',
                );
            }
        }, 3);
    }

    private function auditEvent(
        Business $business,
        string $invitationId,
        ?int $actorUserId,
        string $event,
        ?string $previousStatus,
        string $newStatus,
        ?array $metadata = null,
    ): void {
        DB::table('staff_invitation_events')->insert([
            'id' => (string) Str::ulid(),
            'business_id' => $business->getKey(),
            'staff_invitation_id' => $invitationId,
            'actor_user_id' => $actorUserId,
            'event' => $event,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'metadata' => $metadata === null ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function lockBusiness(Business $business): void
    {
        DB::table('businesses')
            ->where('id', $business->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function effectiveStatus(object $invitation): string
    {
        if ($invitation->status === 'pending' && now()->greaterThanOrEqualTo($invitation->expires_at)) {
            return 'expired';
        }

        return $invitation->status;
    }

    private function decodePermissions(mixed $value): array
    {
        $decoded = is_array($value)
            ? $value
            : json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);

        sort($decoded);

        return array_values($decoded);
    }
}
