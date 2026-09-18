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

beforeEach(fn () => $this->seed(PermissionSeeder::class));

function fptBusiness(string $name): Business
{
    return Business::query()->create([
        'name' => $name,
        'legal_name' => $name.' sh.p.k.',
        'tax_number' => 'L'.Str::upper(Str::random(8)),
        'currency' => 'ALL',
        'timezone' => 'Europe/Tirane',
        'status' => 'active',
    ]);
}

function fptUser(Business $business, array $permissionKeys): User
{
    $user = User::query()->create([
        'name' => 'Fiscal Admin',
        'email' => Str::lower(Str::random(12)).'@example.test',
        'password' => bcrypt('password'),
    ]);
    $role = Role::query()->create([
        'business_id' => $business->id,
        'name' => 'Fiscal Role',
        'slug' => 'fiscal-'.Str::lower(Str::random(8)),
        'is_system' => false,
    ]);
    $role->permissions()->sync(Permission::query()->whereIn('key', $permissionKeys)->pluck('id'));
    $user->businesses()->attach($business->id, ['role_id' => $role->id, 'status' => 'active']);
    Sanctum::actingAs($user);
    return $user;
}

test('fiscalization profile stores only a secret reference and never exposes it', function (): void {
    $business = fptBusiness('Fiscal Profile');
    fptUser($business, ['fiscalization.view','fiscalization.manage']);
    $headers = ['X-Business-Id' => $business->id];

    $this->putJson('/api/v1/fiscalization/profile', [
        'provider' => 'direct_dpt',
        'environment' => 'test',
        'software_code' => 'sw123sw123',
        'is_issuer_in_vat' => true,
        'endpoint' => 'https://example.test/fiscalization',
        'certificate_secret_ref' => 'env:FISCAL_CERTIFICATE_P12',
        'certificate_password_secret_ref' => 'env:FISCAL_CERTIFICATE_PASSWORD',
    ], $headers)->assertOk()
        ->assertJsonPath('data.status', 'configured')
        ->assertJsonPath('data.certificate_reference_configured', true)
        ->assertJsonPath('data.certificate_password_reference_configured', true)
        ->assertJsonPath('data.is_issuer_in_vat', true)
        ->assertJsonPath('data.ready_for_verification', true)
        ->assertJsonMissingPath('data.certificate_secret_ref');

    expect(DB::table('fiscalization_profiles')->where('business_id', $business->id)->value('certificate_secret_ref'))
        ->toBe('env:FISCAL_CERTIFICATE_P12')
        ->and(DB::table('fiscalization_profiles')->where('business_id', $business->id)->value('certificate_password_secret_ref'))
        ->toBe('env:FISCAL_CERTIFICATE_PASSWORD');

    $this->getJson('/api/v1/fiscalization/profile', $headers)->assertOk()
        ->assertJsonPath('data.software_code', 'sw123sw123')
        ->assertJsonPath('data.certificate_reference_configured', true)
        ->assertJsonPath('data.certificate_password_reference_configured', true)
        ->assertJsonMissingPath('data.certificate_secret_ref')
        ->assertJsonMissingPath('data.certificate_password_secret_ref');
});

test('raw certificates and non-https endpoints are rejected before persistence', function (): void {
    $business = fptBusiness('Fiscal Validation');
    fptUser($business, ['fiscalization.view','fiscalization.manage']);
    $headers = ['X-Business-Id' => $business->id];

    $this->putJson('/api/v1/fiscalization/profile', [
        'provider' => 'direct_dpt',
        'environment' => 'test',
        'software_code' => 'sx123sx123',
        'endpoint' => 'http://insecure.example.test',
        'certificate_secret_ref' => '-----BEGIN PRIVATE KEY-----',
    ], $headers)->assertStatus(422)
        ->assertJsonValidationErrors(['endpoint','certificate_secret_ref']);

    expect(DB::table('fiscalization_profiles')->where('business_id', $business->id)->count())->toBe(0);
});

test('fiscalization profile is tenant isolated and manage permission is separate from view', function (): void {
    $a = fptBusiness('Fiscal A');
    $b = fptBusiness('Fiscal B');

    fptUser($a, ['fiscalization.view','fiscalization.manage']);
    $headersA = ['X-Business-Id' => $a->id];
    $this->putJson('/api/v1/fiscalization/profile', [
        'provider' => 'direct_dpt',
        'environment' => 'test',
        'software_code' => 'sa123sa123',
        'is_issuer_in_vat' => true,
        'endpoint' => 'https://a.example.test/fiscal',
        'certificate_secret_ref' => 'secret:a-cert',
    ], $headersA)->assertOk();

    fptUser($b, ['fiscalization.view']);
    $headersB = ['X-Business-Id' => $b->id];

    $this->getJson('/api/v1/fiscalization/profile', $headersB)->assertOk()
        ->assertJsonPath('data.status', 'unconfigured')
        ->assertJsonPath('data.software_code', null);

    $this->putJson('/api/v1/fiscalization/profile', [
        'provider' => 'direct_dpt',
        'environment' => 'test',
        'software_code' => 'sb123sb123',
        'is_issuer_in_vat' => true,
        'endpoint' => 'https://b.example.test/fiscal',
        'certificate_secret_ref' => 'secret:b-cert',
    ], $headersB)->assertForbidden();

    expect(DB::table('fiscalization_profiles')->where('business_id', $a->id)->value('software_code'))->toBe('sa123sa123')
        ->and(DB::table('fiscalization_profiles')->where('business_id', $b->id)->count())->toBe(0);
});
