<?php

use App\Models\Business;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    config()->set('app.frontend_url', 'https://hospitality.example.test');
});

function simBusiness(string $name): Business
{
    return Business::query()->create([
        'name' => $name,
        'currency' => 'EUR',
        'timezone' => 'Europe/Berlin',
        'status' => 'active',
    ]);
}

function simRole(Business $business, string $name, array $permissionKeys): Role
{
    $role = Role::query()->create([
        'business_id' => $business->getKey(),
        'name' => $name,
        'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
        'is_system' => false,
    ]);

    $role->permissions()->sync(
        Permission::query()->whereIn('key', $permissionKeys)->pluck('id')
    );

    return $role;
}

function simUser(
    Business $business,
    array $permissionKeys,
    string $email,
    string $name = 'Staff Manager',
): array {
    $user = User::query()->create([
        'name' => $name,
        'email' => $email,
        'password' => 'Manager#Password123',
    ]);

    $role = simRole($business, $name.' Role', $permissionKeys);

    $user->businesses()->attach($business->getKey(), [
        'role_id' => $role->getKey(),
        'status' => 'active',
    ]);

    return [$user, $role];
}

function simHeaders(User $user, Business $business): array
{
    Sanctum::actingAs($user);

    return ['X-Business-Id' => $business->getKey()];
}

function simTokenFromUrl(string $url): string
{
    return basename((string) parse_url($url, PHP_URL_PATH));
}

test('manager can issue a one-time invitation without storing the raw token', function (): void {
    $business = simBusiness('Invite Business');
    [$manager] = simUser(
        $business,
        ['users.manage', 'users.view', 'orders.view'],
        'manager@example.test',
    );
    $waiterRole = simRole($business, 'Waiter', ['orders.view']);

    $response = $this->postJson('/api/v1/staff-invitations', [
        'email' => ' NEW.STAFF@Example.Test ',
        'role_id' => $waiterRole->getKey(),
        'expires_in_days' => 7,
    ], simHeaders($manager, $business))->assertCreated()
        ->assertJsonPath('data.email', 'new.staff@example.test')
        ->assertJsonPath('data.role_name', 'Waiter')
        ->assertJsonPath('data.status', 'pending');

    $url = $response->json('data.invitation_url');
    $token = simTokenFromUrl($url);

    expect($url)->toStartWith('https://hospitality.example.test/join/')
        ->and(strlen($token))->toBe(64);

    $row = DB::table('staff_invitations')->where('id', $response->json('data.id'))->first();

    expect($row)->not->toBeNull()
        ->and($row->email)->toBe('new.staff@example.test')
        ->and($row->token_hash)->toBe(hash('sha256', $token))
        ->and($row->token_hash)->not->toContain($token)
        ->and(json_decode($row->role_permissions_snapshot, true))->toBe(['orders.view']);

    $preview = $this->getJson('/api/v1/invitations/'.$token)
        ->assertOk()
        ->assertJsonPath('data.business_name', 'Invite Business')
        ->assertJsonPath('data.role_name', 'Waiter')
        ->assertJsonPath('data.email', 'new.staff@example.test')
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.existing_user', false);

    expect(json_encode($preview->json()))->not->toContain($row->token_hash);

    $list = $this->getJson('/api/v1/staff-invitations', simHeaders($manager, $business))
        ->assertOk()
        ->assertJsonPath('data.0.email', 'new.staff@example.test')
        ->assertJsonPath('data.0.role_is_current', true);

    expect(json_encode($list->json()))->not->toContain($token)
        ->and(json_encode($list->json()))->not->toContain($row->token_hash);
});

test('new invited staff account is created atomically and invitation is one-time', function (): void {
    $business = simBusiness('New Account Invite');
    [$manager] = simUser(
        $business,
        ['users.manage', 'users.view', 'orders.view'],
        'new-account-manager@example.test',
    );
    $role = simRole($business, 'Server', ['orders.view']);

    $invite = $this->postJson('/api/v1/staff-invitations', [
        'email' => 'server@example.test',
        'role_id' => $role->getKey(),
        'expires_in_days' => 3,
    ], simHeaders($manager, $business))->assertCreated();

    $token = simTokenFromUrl($invite->json('data.invitation_url'));

    // The invite acceptance endpoint intentionally establishes a fresh web session.
    // Clear Sanctum's test-only actor so the next /auth/me assertion proves that session.
    app('auth')->forgetGuards();

    $this->postJson('/api/v1/invitations/'.$token.'/accept', [
        'name' => 'New Server',
        'password' => 'Strong#Password123',
        'password_confirmation' => 'Strong#Password123',
    ])->assertOk()
        ->assertJsonPath('data.name', 'New Server')
        ->assertJsonPath('data.email', 'server@example.test');

    $user = User::query()->where('email', 'server@example.test')->firstOrFail();

    expect(DB::table('business_user')
        ->where('business_id', $business->getKey())
        ->where('user_id', $user->getKey())
        ->value('role_id'))->toBe($role->getKey())
        ->and(DB::table('business_user')
            ->where('business_id', $business->getKey())
            ->where('user_id', $user->getKey())
            ->value('status'))->toBe('active');

    $invitation = DB::table('staff_invitations')->where('id', $invite->json('data.id'))->first();
    expect($invitation->status)->toBe('accepted')
        ->and((int) $invitation->accepted_by_user_id)->toBe($user->getKey())
        ->and($invitation->accepted_at)->not->toBeNull();

    $audit = DB::table('business_membership_audits')
        ->where('business_id', $business->getKey())
        ->where('target_user_id', $user->getKey())
        ->where('action', 'invitation_accepted')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->previous_status)->toBe('none')
        ->and($audit->new_role_id)->toBe($role->getKey())
        ->and($audit->new_status)->toBe('active');

    $this->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.email', 'server@example.test')
        ->assertJsonPath('data.businesses.0.id', $business->getKey());

    $this->postJson('/api/v1/invitations/'.$token.'/accept', [
        'name' => 'Replay',
        'password' => 'Strong#Password123',
        'password_confirmation' => 'Strong#Password123',
    ])->assertStatus(422)
        ->assertJsonValidationErrors('invitation');

    expect(DB::table('business_user')
        ->where('business_id', $business->getKey())
        ->where('user_id', $user->getKey())
        ->count())->toBe(1);
});

test('existing user must prove account password and keeps their existing identity', function (): void {
    $business = simBusiness('Existing Account Invite');
    [$manager] = simUser(
        $business,
        ['users.manage', 'users.view', 'orders.view'],
        'existing-manager@example.test',
    );
    $role = simRole($business, 'Existing Server', ['orders.view']);

    $existing = User::query()->create([
        'name' => 'Existing Identity',
        'email' => 'existing@example.test',
        'password' => 'Existing#Password123',
    ]);

    $invite = $this->postJson('/api/v1/staff-invitations', [
        'email' => $existing->email,
        'role_id' => $role->getKey(),
        'expires_in_days' => 5,
    ], simHeaders($manager, $business))->assertCreated();

    $token = simTokenFromUrl($invite->json('data.invitation_url'));

    $this->getJson('/api/v1/invitations/'.$token)
        ->assertOk()
        ->assertJsonPath('data.existing_user', true);

    $this->postJson('/api/v1/invitations/'.$token.'/accept', [
        'name' => 'Should Not Replace',
        'password' => 'Wrong#Password123',
        'password_confirmation' => 'Wrong#Password123',
    ])->assertStatus(422)
        ->assertJsonValidationErrors('password');

    expect(DB::table('business_user')
        ->where('business_id', $business->getKey())
        ->where('user_id', $existing->getKey())
        ->exists())->toBeFalse();

    $this->postJson('/api/v1/invitations/'.$token.'/accept', [
        'name' => 'Should Not Replace',
        'password' => 'Existing#Password123',
        'password_confirmation' => 'Existing#Password123',
    ])->assertOk()
        ->assertJsonPath('data.name', 'Existing Identity');

    expect($existing->refresh()->name)->toBe('Existing Identity')
        ->and(DB::table('business_user')
            ->where('business_id', $business->getKey())
            ->where('user_id', $existing->getKey())
            ->value('role_id'))->toBe($role->getKey());
});

test('expired and revoked invitations cannot be accepted and expired email can be reissued', function (): void {
    $business = simBusiness('Invitation States');
    [$manager] = simUser(
        $business,
        ['users.manage', 'users.view', 'orders.view'],
        'state-manager@example.test',
    );
    $role = simRole($business, 'State Role', ['orders.view']);
    $headers = simHeaders($manager, $business);

    $expired = $this->postJson('/api/v1/staff-invitations', [
        'email' => 'expired@example.test',
        'role_id' => $role->getKey(),
        'expires_in_days' => 1,
    ], $headers)->assertCreated();

    $expiredToken = simTokenFromUrl($expired->json('data.invitation_url'));
    DB::table('staff_invitations')
        ->where('id', $expired->json('data.id'))
        ->update(['expires_at' => now()->subMinute()]);

    $this->getJson('/api/v1/invitations/'.$expiredToken)
        ->assertOk()
        ->assertJsonPath('data.status', 'expired');

    $this->postJson('/api/v1/invitations/'.$expiredToken.'/accept', [
        'name' => 'Expired User',
        'password' => 'Strong#Password123',
        'password_confirmation' => 'Strong#Password123',
    ])->assertStatus(422)
        ->assertJsonValidationErrors('invitation');

    $this->postJson('/api/v1/staff-invitations', [
        'email' => 'expired@example.test',
        'role_id' => $role->getKey(),
        'expires_in_days' => 2,
    ], $headers)->assertCreated();

    $revoked = $this->postJson('/api/v1/staff-invitations', [
        'email' => 'revoked@example.test',
        'role_id' => $role->getKey(),
        'expires_in_days' => 2,
    ], $headers)->assertCreated();

    $revokedToken = simTokenFromUrl($revoked->json('data.invitation_url'));

    $this->postJson('/api/v1/staff-invitations/'.$revoked->json('data.id').'/revoke', [], $headers)
        ->assertOk()
        ->assertJsonPath('message', 'Staff invitation revoked.');

    $this->getJson('/api/v1/invitations/'.$revokedToken)
        ->assertOk()
        ->assertJsonPath('data.status', 'revoked');

    $this->postJson('/api/v1/invitations/'.$revokedToken.'/accept', [
        'name' => 'Revoked User',
        'password' => 'Strong#Password123',
        'password_confirmation' => 'Strong#Password123',
    ])->assertStatus(422)
        ->assertJsonValidationErrors('invitation');
});

test('duplicate pending invites and existing memberships are rejected', function (): void {
    $business = simBusiness('Duplicate Invite');
    [$manager] = simUser(
        $business,
        ['users.manage', 'users.view', 'orders.view'],
        'duplicate-manager@example.test',
    );
    $role = simRole($business, 'Duplicate Role', ['orders.view']);
    $headers = simHeaders($manager, $business);

    $payload = [
        'email' => 'duplicate@example.test',
        'role_id' => $role->getKey(),
        'expires_in_days' => 7,
    ];

    $this->postJson('/api/v1/staff-invitations', $payload, $headers)->assertCreated();
    $this->postJson('/api/v1/staff-invitations', $payload, $headers)
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');

    $member = User::query()->create([
        'name' => 'Existing Member',
        'email' => 'member@example.test',
        'password' => 'Existing#Password123',
    ]);
    $member->businesses()->attach($business->getKey(), [
        'role_id' => $role->getKey(),
        'status' => 'inactive',
    ]);

    $this->postJson('/api/v1/staff-invitations', [
        ...$payload,
        'email' => $member->email,
    ], $headers)->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

test('role drift and lost inviter authority invalidate outstanding invitations', function (): void {
    $business = simBusiness('Invite Authority');
    [$manager, $managerRole] = simUser(
        $business,
        ['users.manage', 'users.view', 'orders.view', 'products.view'],
        'authority-manager@example.test',
    );
    $target = simRole($business, 'Authority Target', ['orders.view']);
    $headers = simHeaders($manager, $business);

    $drifted = $this->postJson('/api/v1/staff-invitations', [
        'email' => 'drift@example.test',
        'role_id' => $target->getKey(),
        'expires_in_days' => 7,
    ], $headers)->assertCreated();

    $driftedToken = simTokenFromUrl($drifted->json('data.invitation_url'));

    $target->permissions()->sync(
        Permission::query()->whereIn('key', ['orders.view', 'products.view'])->pluck('id')
    );

    $this->getJson('/api/v1/staff-invitations', $headers)
        ->assertOk()
        ->assertJsonPath('data.0.role_is_current', false);

    $this->postJson('/api/v1/invitations/'.$driftedToken.'/accept', [
        'name' => 'Drift User',
        'password' => 'Strong#Password123',
        'password_confirmation' => 'Strong#Password123',
    ])->assertStatus(422)
        ->assertJsonValidationErrors('invitation');

    $target->permissions()->sync(
        Permission::query()->where('key', 'orders.view')->pluck('id')
    );

    $authorityLost = $this->postJson('/api/v1/staff-invitations', [
        'email' => 'lost-authority@example.test',
        'role_id' => $target->getKey(),
        'expires_in_days' => 7,
    ], $headers)->assertCreated();

    $authorityToken = simTokenFromUrl($authorityLost->json('data.invitation_url'));

    $managerRole->permissions()->sync(
        Permission::query()->whereIn('key', ['users.manage', 'users.view'])->pluck('id')
    );

    $this->postJson('/api/v1/invitations/'.$authorityToken.'/accept', [
        'name' => 'Authority User',
        'password' => 'Strong#Password123',
        'password_confirmation' => 'Strong#Password123',
    ])->assertStatus(422)
        ->assertJsonValidationErrors('invitation');

    expect(User::query()->where('email', 'lost-authority@example.test')->exists())->toBeFalse();
});

test('invitation administration is permission and tenant scoped', function (): void {
    $businessA = simBusiness('Invite Tenant A');
    $businessB = simBusiness('Invite Tenant B');
    [$managerA] = simUser(
        $businessA,
        ['users.manage', 'users.view', 'orders.view'],
        'manager-a@example.test',
    );
    [$managerB] = simUser(
        $businessB,
        ['users.manage', 'users.view', 'orders.view'],
        'manager-b@example.test',
    );
    [$viewerA] = simUser(
        $businessA,
        ['users.view'],
        'viewer-a@example.test',
        'Viewer',
    );

    $roleA = simRole($businessA, 'A Role', ['orders.view']);
    $roleB = simRole($businessB, 'B Role', ['orders.view']);

    $inviteB = $this->postJson('/api/v1/staff-invitations', [
        'email' => 'tenant-b@example.test',
        'role_id' => $roleB->getKey(),
        'expires_in_days' => 7,
    ], simHeaders($managerB, $businessB))->assertCreated();

    $this->getJson('/api/v1/staff-invitations', simHeaders($viewerA, $businessA))
        ->assertForbidden();

    $this->postJson('/api/v1/staff-invitations', [
        'email' => 'blocked@example.test',
        'role_id' => $roleA->getKey(),
        'expires_in_days' => 7,
    ], simHeaders($viewerA, $businessA))->assertForbidden();

    $this->postJson('/api/v1/staff-invitations/'.$inviteB->json('data.id').'/revoke', [], simHeaders($managerA, $businessA))
        ->assertNotFound();

    expect(DB::table('staff_invitations')->where('id', $inviteB->json('data.id'))->value('status'))->toBe('pending');
});

test('users manage cannot assign or invite roles above their own permission level', function (): void {
    $business = simBusiness('Delegation Enforcement');
    [$manager] = simUser(
        $business,
        ['users.manage', 'users.view'],
        'limited-manager@example.test',
    );
    $lowRole = simRole($business, 'Low Role', ['users.view']);
    $elevatedRole = simRole($business, 'Elevated Role', ['orders.view']);
    $headers = simHeaders($manager, $business);

    $member = User::query()->create([
        'name' => 'Low Member',
        'email' => 'low-member@example.test',
        'password' => 'Existing#Password123',
    ]);
    $member->businesses()->attach($business->getKey(), [
        'role_id' => $lowRole->getKey(),
        'status' => 'active',
    ]);

    $this->patchJson('/api/v1/staff/'.$member->getKey(), [
        'role_id' => $elevatedRole->getKey(),
        'status' => 'active',
    ], $headers)->assertStatus(422)
        ->assertJsonValidationErrors('role_id');

    $this->postJson('/api/v1/staff-invitations', [
        'email' => 'escalation@example.test',
        'role_id' => $elevatedRole->getKey(),
        'expires_in_days' => 7,
    ], $headers)->assertStatus(422)
        ->assertJsonValidationErrors('role_id');

    $staff = $this->getJson('/api/v1/staff', $headers)->assertOk()->json('data');

    expect($staff['assignable_role_ids'])->toContain($lowRole->getKey())
        ->and($staff['assignable_role_ids'])->not->toContain($elevatedRole->getKey());
});
