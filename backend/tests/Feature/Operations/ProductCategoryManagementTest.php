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
});

function ccmBusiness(string $name): Business
{
    return Business::query()->create([
        'name' => $name,
        'currency' => 'EUR',
        'timezone' => 'Europe/Berlin',
        'status' => 'active',
    ]);
}

function ccmUser(Business $business, array $permissionKeys): User
{
    $user = User::query()->create([
        'name' => 'Catalog Manager',
        'email' => Str::lower(Str::random(12)).'@example.test',
        'password' => 'test-password',
    ]);

    $role = Role::query()->create([
        'business_id' => $business->getKey(),
        'name' => 'Catalog Role',
        'slug' => 'catalog-'.Str::lower(Str::random(8)),
        'is_system' => false,
    ]);

    $role->permissions()->sync(
        Permission::query()->whereIn('key', $permissionKeys)->pluck('id')
    );

    $user->businesses()->attach($business->getKey(), [
        'role_id' => $role->getKey(),
        'status' => 'active',
    ]);

    return $user;
}

function ccmHeaders(User $user, Business $business): array
{
    Sanctum::actingAs($user);

    return ['X-Business-Id' => $business->getKey()];
}

function ccmProduct(Business $business, ?string $categoryId, string $name, bool $active = true): string
{
    $id = (string) Str::ulid();

    DB::table('products')->insert([
        'id' => $id,
        'business_id' => $business->getKey(),
        'product_category_id' => $categoryId,
        'name' => $name,
        'sale_price' => '4.0000',
        'tax_rate' => '20.0000',
        'unit_code' => 'C62',
        'unit_label' => 'Copë',
        'tracks_stock' => false,
        'is_active' => $active,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

test('category management creates updates lists counts and preserves ordering', function (): void {
    $business = ccmBusiness('Category CRUD');
    $user = ccmUser($business, ['products.view', 'products.manage']);
    $headers = ccmHeaders($user, $business);

    $coffee = $this->postJson('/api/v1/management/categories', [
        'name' => 'Coffee',
        'color' => '#6F4E37',
        'sort_order' => 20,
    ], $headers)->assertCreated()
        ->assertJsonPath('data.name', 'Coffee')
        ->assertJsonPath('data.color', '#6F4E37')
        ->assertJsonPath('data.sort_order', 20)
        ->assertJsonPath('data.is_active', true)
        ->json('data.id');

    $tea = $this->postJson('/api/v1/management/categories', [
        'name' => 'Tea',
        'color' => '#4F7942',
        'sort_order' => 10,
    ], $headers)->assertCreated()->json('data.id');

    ccmProduct($business, $coffee, 'Espresso', true);
    ccmProduct($business, $coffee, 'Legacy Coffee', false);
    ccmProduct($business, $tea, 'Green Tea', true);

    $list = $this->getJson('/api/v1/management/categories', $headers)
        ->assertOk()
        ->json('data');

    expect($list)->toHaveCount(2)
        ->and($list[0]['id'])->toBe($tea)
        ->and($list[1]['id'])->toBe($coffee)
        ->and($list[1]['product_count'])->toBe(2)
        ->and($list[1]['active_product_count'])->toBe(1);

    $this->postJson('/api/v1/management/categories', [
        'id' => $coffee,
        'name' => 'Specialty Coffee',
        'color' => '#7A5137',
        'sort_order' => 5,
    ], $headers)->assertOk()
        ->assertJsonPath('data.name', 'Specialty Coffee')
        ->assertJsonPath('data.sort_order', 5);

    expect(DB::table('product_categories')->where('id', $coffee)->value('name'))->toBe('Specialty Coffee');
});

test('category names are unique inside a business but reusable across businesses', function (): void {
    $businessA = ccmBusiness('Category A');
    $businessB = ccmBusiness('Category B');
    $userA = ccmUser($businessA, ['products.view', 'products.manage']);
    $userB = ccmUser($businessB, ['products.view', 'products.manage']);

    $payload = ['name' => 'Coffee', 'color' => null, 'sort_order' => 1];

    $this->postJson('/api/v1/management/categories', $payload, ccmHeaders($userA, $businessA))->assertCreated();
    $this->postJson('/api/v1/management/categories', $payload, ccmHeaders($userA, $businessA))
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');

    $this->postJson('/api/v1/management/categories', [...$payload, 'name' => 'coffee'], ccmHeaders($userA, $businessA))
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');

    $this->postJson('/api/v1/management/categories', $payload, ccmHeaders($userB, $businessB))->assertCreated();
});

test('category disable is blocked while active products still depend on it', function (): void {
    $business = ccmBusiness('Category Status');
    $user = ccmUser($business, ['products.view', 'products.manage']);
    $headers = ccmHeaders($user, $business);

    $category = $this->postJson('/api/v1/management/categories', [
        'name' => 'Cocktails',
        'color' => '#AA3355',
        'sort_order' => 1,
    ], $headers)->assertCreated()->json('data.id');

    $product = ccmProduct($business, $category, 'Negroni', true);

    $this->patchJson("/api/v1/management/categories/{$category}/status", ['is_active' => false], $headers)
        ->assertStatus(422)
        ->assertJsonValidationErrors('category');

    expect((bool) DB::table('product_categories')->where('id', $category)->value('is_active'))->toBeTrue();

    DB::table('products')->where('id', $product)->update(['is_active' => false, 'updated_at' => now()]);

    $this->patchJson("/api/v1/management/categories/{$category}/status", ['is_active' => false], $headers)
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    $this->patchJson("/api/v1/management/products/{$product}/status", ['is_active' => true], $headers)
        ->assertStatus(422)
        ->assertJsonValidationErrors('product');

    expect((bool) DB::table('products')->where('id', $product)->value('is_active'))->toBeFalse();

    $this->patchJson("/api/v1/management/categories/{$category}/status", ['is_active' => true], $headers)
        ->assertOk()
        ->assertJsonPath('data.is_active', true);
});

test('category mutation cannot cross tenant boundaries', function (): void {
    $businessA = ccmBusiness('Category Tenant A');
    $businessB = ccmBusiness('Category Tenant B');
    $userA = ccmUser($businessA, ['products.view', 'products.manage']);
    $userB = ccmUser($businessB, ['products.view', 'products.manage']);

    $foreign = $this->postJson('/api/v1/management/categories', [
        'name' => 'Foreign Category',
        'color' => '#112233',
        'sort_order' => 0,
    ], ccmHeaders($userB, $businessB))->assertCreated()->json('data.id');

    $headersA = ccmHeaders($userA, $businessA);

    $this->postJson('/api/v1/management/categories', [
        'id' => $foreign,
        'name' => 'Hijacked',
        'color' => '#223344',
        'sort_order' => 0,
    ], $headersA)->assertNotFound();

    $this->patchJson("/api/v1/management/categories/{$foreign}/status", ['is_active' => false], $headersA)
        ->assertNotFound();

    expect(DB::table('product_categories')->where('id', $foreign)->value('name'))->toBe('Foreign Category');
});

test('category reads require products view and writes require products manage', function (): void {
    $business = ccmBusiness('Category Permissions');
    $viewer = ccmUser($business, ['products.view']);
    $viewerHeaders = ccmHeaders($viewer, $business);

    $this->getJson('/api/v1/management/categories', $viewerHeaders)->assertOk();
    $this->postJson('/api/v1/management/categories', [
        'name' => 'Unauthorized',
        'color' => '#111111',
        'sort_order' => 0,
    ], $viewerHeaders)->assertForbidden();

    $noAccess = ccmUser($business, ['orders.view']);
    $this->getJson('/api/v1/management/categories', ccmHeaders($noAccess, $business))->assertForbidden();
});
