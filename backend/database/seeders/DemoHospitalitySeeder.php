<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use App\Services\Authorization\ProvisionBusinessRoles;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class DemoHospitalitySeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException('DemoHospitalitySeeder must never run in production.');
        }

        $this->call([PermissionSeeder::class, RoleTemplateSeeder::class]);

        DB::transaction(function (): void {
            $business = Business::query()->updateOrCreate(
                ['tax_number' => 'DEMO-HOSPITALITY-001'],
                ['name' => 'PiCenter Café & Lounge', 'legal_name' => 'PiCenter Hospitality Demo', 'currency' => 'EUR', 'timezone' => 'Europe/Berlin', 'status' => 'active']
            );

            $location = Location::query()->updateOrCreate(
                ['business_id' => $business->id, 'code' => 'BERLIN-MAIN'],
                ['name' => 'Berlin Mitte', 'type' => 'bar_cafe', 'address' => 'Demo Location · Berlin', 'is_active' => true]
            );

            app(ProvisionBusinessRoles::class)->handle($business);
            $ownerRole = Role::query()->where('business_id', $business->id)->where('slug', 'owner')->firstOrFail();

            $owner = User::query()->updateOrCreate(
                ['email' => 'owner@demo.local'],
                ['name' => 'Demo Owner', 'password' => Hash::make('Demo123!'), 'email_verified_at' => now()]
            );
            DB::table('business_user')->updateOrInsert(
                ['business_id' => $business->id, 'user_id' => $owner->id],
                ['role_id' => $ownerRole->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]
            );

            foreach ([
                ['manager@demo.local', 'Demo Manager', 'manager'],
                ['waiter@demo.local', 'Demo Waiter', 'waiter'],
                ['bartender@demo.local', 'Demo Bartender', 'bartender'],
                ['cashier@demo.local', 'Demo Cashier', 'cashier'],
            ] as [$email, $name, $slug]) {
                $user = User::query()->updateOrCreate(['email' => $email], ['name' => $name, 'password' => Hash::make('Demo123!'), 'email_verified_at' => now()]);
                $role = Role::query()->where('business_id', $business->id)->where('slug', $slug)->firstOrFail();
                DB::table('business_user')->updateOrInsert(['business_id' => $business->id, 'user_id' => $user->id], ['role_id' => $role->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            }

            $areas = [];
            foreach ([['Indoor', 1], ['Terrace', 2], ['Bar Counter', 3]] as [$name, $sort]) {
                $id = $this->ulidFor('venue_areas', ['business_id' => $business->id, 'location_id' => $location->id, 'name' => $name], ['sort_order' => $sort]);
                $areas[$name] = $id;
            }
            foreach ([['T01', 'Indoor', 4], ['T02', 'Indoor', 4], ['T03', 'Indoor', 2], ['T04', 'Terrace', 4], ['T05', 'Terrace', 6], ['BAR-1', 'Bar Counter', 2]] as [$name, $area, $capacity]) {
                $this->ulidFor('venue_tables', ['location_id' => $location->id, 'name' => $name], ['business_id' => $business->id, 'venue_area_id' => $areas[$area], 'capacity' => $capacity, 'is_active' => true]);
            }

            $categories = [];
            foreach ([['Coffee', '#7C4D2A', 1], ['Cold Drinks', '#2563EB', 2], ['Cocktails', '#7C3AED', 3], ['Beer & Wine', '#D97706', 4], ['Snacks', '#059669', 5]] as [$name, $color, $sort]) {
                $categories[$name] = $this->ulidFor('product_categories', ['business_id' => $business->id, 'name' => $name], ['color' => $color, 'sort_order' => $sort, 'is_active' => true]);
            }

            $products = [
                ['Espresso', 'COF-ESP', 'Coffee', '2.5000', '19.0000', 'bar', false],
                ['Cappuccino', 'COF-CAP', 'Coffee', '3.8000', '19.0000', 'bar', false],
                ['Latte Macchiato', 'COF-LAT', 'Coffee', '4.2000', '19.0000', 'bar', false],
                ['Fresh Orange Juice', 'DRK-OJ', 'Cold Drinks', '4.9000', '19.0000', 'bar', true],
                ['Sparkling Water', 'DRK-WTR', 'Cold Drinks', '2.8000', '19.0000', 'bar', true],
                ['Mojito', 'CKT-MOJ', 'Cocktails', '9.5000', '19.0000', 'bar', false],
                ['Aperol Spritz', 'CKT-APE', 'Cocktails', '9.0000', '19.0000', 'bar', false],
                ['House Beer 0.5L', 'BEV-BEER', 'Beer & Wine', '5.5000', '19.0000', 'bar', true],
                ['House White Wine', 'BEV-WINE', 'Beer & Wine', '6.5000', '19.0000', 'bar', true],
                ['Club Sandwich', 'SNK-CLUB', 'Snacks', '8.9000', '7.0000', 'kitchen', true],
                ['Croissant', 'SNK-CRO', 'Snacks', '3.2000', '7.0000', 'kitchen', true],
            ];
            $productIds = [];
            foreach ($products as [$name, $sku, $category, $price, $tax, $station, $stock]) {
                $productIds[$sku] = $this->ulidFor('products', ['business_id' => $business->id, 'sku' => $sku], ['product_category_id' => $categories[$category], 'name' => $name, 'sale_price' => $price, 'tax_rate' => $tax, 'preparation_station' => $station, 'tracks_stock' => $stock, 'is_active' => true]);
                if ($stock) {
                    DB::table('inventory_stocks')->updateOrInsert(['business_id' => $business->id, 'location_id' => $location->id, 'product_id' => $productIds[$sku]], ['id' => $this->existingOrNewId('inventory_stocks', ['business_id' => $business->id, 'location_id' => $location->id, 'product_id' => $productIds[$sku]]), 'quantity_on_hand' => match ($sku) {'DRK-OJ' => '24.0000', 'DRK-WTR' => '72.0000', 'BEV-BEER' => '48.0000', 'BEV-WINE' => '18.0000', 'SNK-CLUB' => '20.0000', default => '35.0000'}, 'reorder_level' => '10.0000', 'created_at' => now(), 'updated_at' => now()]);
                }
            }

            $registerId = $this->ulidFor('cash_registers', ['business_id' => $business->id, 'code' => 'MAIN-01'], ['location_id' => $location->id, 'name' => 'Main Register', 'is_active' => true]);
            $sessionId = $this->existingOrNewId('cash_sessions', ['business_id' => $business->id, 'cash_register_id' => $registerId, 'status' => 'open']);
            DB::table('cash_sessions')->updateOrInsert(['business_id' => $business->id, 'cash_register_id' => $registerId, 'status' => 'open'], ['id' => $sessionId, 'location_id' => $location->id, 'opened_by_user_id' => $owner->id, 'base_currency' => 'EUR', 'opening_cash' => '150.0000', 'opened_at' => now()->subHours(3), 'created_at' => now(), 'updated_at' => now()]);

            foreach ([
                ['Rent', 'Monthly venue rent', '1200.0000'],
                ['Supplies', 'Coffee beans and milk delivery', '185.5000'],
                ['Utilities', 'Internet and utilities', '96.3000'],
            ] as [$category, $description, $amount]) {
                $id = $this->existingOrNewId('expenses', ['business_id' => $business->id, 'description' => $description]);
                DB::table('expenses')->updateOrInsert(['business_id' => $business->id, 'description' => $description], ['id' => $id, 'location_id' => $location->id, 'created_by_user_id' => $owner->id, 'category' => $category, 'amount' => $amount, 'currency' => 'EUR', 'expense_date' => now()->toDateString(), 'status' => 'posted', 'created_at' => now(), 'updated_at' => now()]);
            }

            foreach (['receipt.footer' => 'Thank you for visiting PiCenter Café!', 'pos.default_location' => $location->id, 'business.display_name' => 'PiCenter Café & Lounge'] as $key => $value) {
                DB::table('business_settings')->updateOrInsert(['business_id' => $business->id, 'key' => $key], ['id' => $this->existingOrNewId('business_settings', ['business_id' => $business->id, 'key' => $key]), 'value' => json_encode($value), 'created_at' => now(), 'updated_at' => now()]);
            }
        });
    }

    private function ulidFor(string $table, array $identity, array $values): string
    {
        $id = $this->existingOrNewId($table, $identity);
        DB::table($table)->updateOrInsert($identity, ['id' => $id, ...$values, 'created_at' => now(), 'updated_at' => now()]);
        return $id;
    }

    private function existingOrNewId(string $table, array $identity): string
    {
        return (string) (DB::table($table)->where($identity)->value('id') ?: Str::ulid());
    }
}
