<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

final class DemoPurchasingSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['businesses', 'locations', 'product_categories', 'products', 'inventory_stocks', 'suppliers', 'preparation_stations'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Missing table {$table}. Run php artisan migrate before this seeder.");
            }
        }

        $business = DB::table('businesses')
            ->where('status', 'active')
            ->orderBy('created_at')
            ->first();

        if (! $business) {
            throw new RuntimeException('No active business found. Create/login to a business first, then run this seeder again.');
        }

        $location = DB::table('locations')
            ->where('business_id', $business->id)
            ->where('is_active', true)
            ->orderBy('created_at')
            ->first();

        if (! $location) {
            throw new RuntimeException('No active location found for the first active business.');
        }

        $now = now();

        $categories = [
            ['name' => 'Coffee & Tea', 'color' => '#7B5E45', 'sort_order' => 10],
            ['name' => 'Soft Drinks', 'color' => '#3B82F6', 'sort_order' => 20],
            ['name' => 'Beer & Wine', 'color' => '#B45309', 'sort_order' => 30],
            ['name' => 'Spirits', 'color' => '#7C3AED', 'sort_order' => 40],
            ['name' => 'Food & Snacks', 'color' => '#16A34A', 'sort_order' => 50],
        ];

        $categoryIds = [];

        foreach ($categories as $category) {
            $existing = DB::table('product_categories')
                ->where('business_id', $business->id)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($category['name'])])
                ->first();

            if ($existing) {
                DB::table('product_categories')
                    ->where('id', $existing->id)
                    ->update([
                        'color' => $category['color'],
                        'sort_order' => $category['sort_order'],
                        'is_active' => true,
                        'updated_at' => $now,
                    ]);

                $categoryIds[$category['name']] = $existing->id;
                continue;
            }

            $id = (string) Str::ulid();
            DB::table('product_categories')->insert([
                'id' => $id,
                'business_id' => $business->id,
                'name' => $category['name'],
                'color' => $category['color'],
                'sort_order' => $category['sort_order'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $categoryIds[$category['name']] = $id;
        }

        $stations = [
            ['name' => 'Bar', 'code' => 'bar', 'sort_order' => 10],
            ['name' => 'Kitchen', 'code' => 'kitchen', 'sort_order' => 20],
        ];

        foreach ($stations as $station) {
            $existing = DB::table('preparation_stations')
                ->where('business_id', $business->id)
                ->where('code', $station['code'])
                ->first();

            if ($existing) {
                DB::table('preparation_stations')
                    ->where('id', $existing->id)
                    ->update([
                        'name' => $station['name'],
                        'sort_order' => $station['sort_order'],
                        'is_active' => true,
                        'updated_at' => $now,
                    ]);
                continue;
            }

            DB::table('preparation_stations')->insert([
                'id' => (string) Str::ulid(),
                'business_id' => $business->id,
                'name' => $station['name'],
                'code' => $station['code'],
                'sort_order' => $station['sort_order'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $products = [
            ['Coffee & Tea', 'COF-BEAN-1KG', 'Coffee Beans 1kg', 22.00, 20, 'bar', 'kg', 4],
            ['Coffee & Tea', 'MILK-1L', 'Fresh Milk 1L', 2.20, 20, 'bar', 'L', 8],
            ['Coffee & Tea', 'OAT-1L', 'Oat Milk 1L', 3.40, 20, 'bar', 'L', 4],
            ['Coffee & Tea', 'TEA-BLK-BOX', 'Black Tea Box', 9.50, 20, 'bar', 'box', 3],
            ['Soft Drinks', 'WATER-050', 'Mineral Water 0.5L', 1.50, 20, 'bar', 'pcs', 12],
            ['Soft Drinks', 'SPARK-050', 'Sparkling Water 0.5L', 1.80, 20, 'bar', 'pcs', 8],
            ['Soft Drinks', 'COLA-330', 'Coca-Cola 330ml', 2.50, 20, 'bar', 'pcs', 10],
            ['Soft Drinks', 'FANTA-330', 'Fanta 330ml', 2.50, 20, 'bar', 'pcs', 8],
            ['Soft Drinks', 'TONIC-200', 'Tonic Water 200ml', 2.80, 20, 'bar', 'pcs', 6],
            ['Soft Drinks', 'JUICE-ORG-1L', 'Orange Juice 1L', 4.50, 20, 'bar', 'L', 4],
            ['Beer & Wine', 'BEER-LAGER-330', 'Lager Beer 330ml', 3.50, 20, 'bar', 'pcs', 12],
            ['Beer & Wine', 'WINE-RED-750', 'House Red Wine 750ml', 16.00, 20, 'bar', 'bottle', 4],
            ['Beer & Wine', 'WINE-WHT-750', 'House White Wine 750ml', 16.00, 20, 'bar', 'bottle', 4],
            ['Spirits', 'GIN-700', 'Gin 700ml', 28.00, 20, 'bar', 'bottle', 2],
            ['Spirits', 'VODKA-700', 'Vodka 700ml', 25.00, 20, 'bar', 'bottle', 2],
            ['Spirits', 'WHISKY-700', 'Whisky 700ml', 35.00, 20, 'bar', 'bottle', 2],
            ['Food & Snacks', 'CROISSANT-001', 'Butter Croissant', 3.00, 20, 'kitchen', 'pcs', 6],
            ['Food & Snacks', 'CHIPS-001', 'Potato Chips', 2.80, 20, 'kitchen', 'pcs', 8],
            ['Food & Snacks', 'PEANUT-001', 'Salted Peanuts', 2.50, 20, 'kitchen', 'pcs', 6],
            ['Food & Snacks', 'SANDWICH-001', 'Club Sandwich', 8.50, 20, 'kitchen', 'pcs', 4],
        ];

        foreach ($products as [$categoryName, $sku, $name, $salePrice, $taxRate, $station, $unitLabel, $reorderLevel]) {
            $product = DB::table('products')
                ->where('business_id', $business->id)
                ->where('sku', $sku)
                ->first();

            if ($product) {
                DB::table('products')
                    ->where('id', $product->id)
                    ->update([
                        'product_category_id' => $categoryIds[$categoryName],
                        'name' => $name,
                        'sale_price' => number_format($salePrice, 4, '.', ''),
                        'tax_rate' => number_format($taxRate, 4, '.', ''),
                        'unit_code' => 'C62',
                        'unit_label' => $unitLabel,
                        'preparation_station' => $station,
                        'tracks_stock' => true,
                        'is_active' => true,
                        'updated_at' => $now,
                    ]);

                $productId = $product->id;
            } else {
                $productId = (string) Str::ulid();
                DB::table('products')->insert([
                    'id' => $productId,
                    'business_id' => $business->id,
                    'product_category_id' => $categoryIds[$categoryName],
                    'name' => $name,
                    'sku' => $sku,
                    'barcode' => null,
                    'sale_price' => number_format($salePrice, 4, '.', ''),
                    'tax_rate' => number_format($taxRate, 4, '.', ''),
                    'unit_code' => 'C62',
                    'unit_label' => $unitLabel,
                    'preparation_station' => $station,
                    'tracks_stock' => true,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $stock = DB::table('inventory_stocks')
                ->where('business_id', $business->id)
                ->where('location_id', $location->id)
                ->where('product_id', $productId)
                ->first();

            if ($stock) {
                DB::table('inventory_stocks')
                    ->where('id', $stock->id)
                    ->update([
                        // Preserve the real quantity if this demo seeder is run again.
                        'reorder_level' => number_format($reorderLevel, 4, '.', ''),
                        'updated_at' => $now,
                    ]);
            } else {
                DB::table('inventory_stocks')->insert([
                    'id' => (string) Str::ulid(),
                    'business_id' => $business->id,
                    'location_id' => $location->id,
                    'product_id' => $productId,
                    // Starts at zero so the first PO receipt is visible in Inventory.
                    'quantity_on_hand' => '0.0000',
                    'reorder_level' => number_format($reorderLevel, 4, '.', ''),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $suppliers = [
            [
                'name' => 'Alba Coffee Supply',
                'tax_number' => 'DEMO-COFFEE-001',
                'contact_name' => 'Arben Demo',
                'email' => 'coffee-supplier@example.test',
                'phone' => '+355 69 100 1001',
                'address' => 'Tirana · Demo supplier address',
            ],
            [
                'name' => 'Beverage Distribution Demo',
                'tax_number' => 'DEMO-BEV-002',
                'contact_name' => 'Elira Demo',
                'email' => 'beverages@example.test',
                'phone' => '+355 69 100 1002',
                'address' => 'Tirana · Demo beverage warehouse',
            ],
            [
                'name' => 'Fresh Food Wholesale Demo',
                'tax_number' => 'DEMO-FOOD-003',
                'contact_name' => 'Klea Demo',
                'email' => 'food-supplier@example.test',
                'phone' => '+355 69 100 1003',
                'address' => 'Tirana · Demo food warehouse',
            ],
        ];

        foreach ($suppliers as $supplier) {
            $existing = DB::table('suppliers')
                ->where('business_id', $business->id)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($supplier['name'])])
                ->first();

            if ($existing) {
                DB::table('suppliers')->where('id', $existing->id)->update([
                    ...$supplier,
                    'is_active' => true,
                    'updated_at' => $now,
                ]);
                continue;
            }

            DB::table('suppliers')->insert([
                'id' => (string) Str::ulid(),
                'business_id' => $business->id,
                ...$supplier,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->command?->info('Demo purchasing data ready.');
        $this->command?->line("Business: {$business->name}");
        $this->command?->line("Location: {$location->name}");
        $this->command?->line('Products: '.count($products).' active stock-tracked items, starting at zero stock.');
        $this->command?->line('Suppliers: '.count($suppliers).' active demo suppliers.');
    }
}
