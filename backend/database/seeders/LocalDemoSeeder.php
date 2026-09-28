<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\User;
use App\Services\Authorization\ProvisionBusinessRoles;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

final class LocalDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('LocalDemoSeeder may only run in local/testing environments.');
        }

        $this->call([
            PermissionSeeder::class,
            RoleTemplateSeeder::class,
        ]);

        DB::transaction(function (): void {
            $business = Business::query()->firstOrCreate(
                ['tax_number' => 'L12345678D'],
                [
                    'name' => 'Demo Hospitality',
                    'legal_name' => 'Demo Hospitality SHPK',
                    'currency' => 'ALL',
                    'timezone' => 'Europe/Tirane',
                    'status' => 'active',
                ],
            );

            $business->forceFill([
                'name' => 'Demo Hospitality',
                'legal_name' => 'Demo Hospitality SHPK',
                'currency' => 'ALL',
                'timezone' => 'Europe/Tirane',
                'status' => 'active',
            ])->save();

            $roles = app(ProvisionBusinessRoles::class)->handle($business);

            $users = collect([
                ['name' => 'Demo Owner', 'email' => 'owner@hospitality.local', 'role' => 'owner', 'operator' => 'OP-DEMO-OWNER'],
                ['name' => 'Demo Manager', 'email' => 'manager@hospitality.local', 'role' => 'manager', 'operator' => 'OP-DEMO-MANAGER'],
                ['name' => 'Demo Waiter', 'email' => 'waiter@hospitality.local', 'role' => 'waiter', 'operator' => 'OP-DEMO-WAITER'],
                ['name' => 'Demo Cashier', 'email' => 'cashier@hospitality.local', 'role' => 'cashier', 'operator' => 'OP-DEMO-CASHIER'],
            ])->mapWithKeys(function (array $row) use ($business, $roles): array {
                $user = User::query()->updateOrCreate(
                    ['email' => $row['email']],
                    [
                        'name' => $row['name'],
                        'password' => Hash::make('Demo#Hospitality123!'),
                        'email_verified_at' => now(),
                    ],
                );

                DB::table('business_user')->updateOrInsert(
                    [
                        'business_id' => $business->id,
                        'user_id' => $user->id,
                    ],
                    [
                        'role_id' => $roles[$row['role']]->id,
                        'status' => 'active',
                        'fiscal_operator_code' => $row['operator'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );

                return [$row['role'] => $user];
            });

            $location = DB::table('locations')
                ->where('business_id', $business->id)
                ->where('code', 'MAIN')
                ->first();

            if (! $location) {
                $locationId = (string) Str::ulid();
                DB::table('locations')->insert([
                    'id' => $locationId,
                    'business_id' => $business->id,
                    'name' => 'Demo Central Bar',
                    'code' => 'MAIN',
                    'type' => 'bar_cafe',
                    'address' => 'Rruga e Kavajes, Tirane',
                    'fiscal_business_unit_code' => 'BU-DEMO-001',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $location = DB::table('locations')->where('id', $locationId)->first();
            } else {
                DB::table('locations')->where('id', $location->id)->update([
                    'name' => 'Demo Central Bar',
                    'address' => 'Rruga e Kavajes, Tirane',
                    'fiscal_business_unit_code' => 'BU-DEMO-001',
                    'is_active' => true,
                    'updated_at' => now(),
                ]);
                $location = DB::table('locations')->where('id', $location->id)->first();
            }

            $register = DB::table('cash_registers')
                ->where('business_id', $business->id)
                ->where('code', 'FRONT')
                ->first();

            if (! $register) {
                $registerId = (string) Str::ulid();
                DB::table('cash_registers')->insert([
                    'id' => $registerId,
                    'business_id' => $business->id,
                    'location_id' => $location->id,
                    'name' => 'Front Till',
                    'code' => 'FRONT',
                    'fiscal_tcr_code' => 'TCR-DEMO-001',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $register = DB::table('cash_registers')->where('id', $registerId)->first();
            } else {
                DB::table('cash_registers')->where('id', $register->id)->update([
                    'location_id' => $location->id,
                    'name' => 'Front Till',
                    'fiscal_tcr_code' => 'TCR-DEMO-001',
                    'is_active' => true,
                    'updated_at' => now(),
                ]);
                $register = DB::table('cash_registers')->where('id', $register->id)->first();
            }

            DB::table('fiscalization_profiles')->updateOrInsert(
                ['business_id' => $business->id],
                [
                    'id' => DB::table('fiscalization_profiles')->where('business_id', $business->id)->value('id') ?? (string) Str::ulid(),
                    'provider' => 'direct_dpt',
                    'environment' => 'test',
                    'status' => 'configured',
                    'software_code' => 'DEMO-SW-001',
                    'certificate_secret_ref' => 'local://demo/fiscal-cert.p12',
                    'certificate_password_secret_ref' => 'local://demo/fiscal-cert-password',
                    'is_issuer_in_vat' => true,
                    'endpoint' => 'https://demo.invalid/fiscalization',
                    'last_verified_at' => null,
                    'last_test_verified_at' => null,
                    'last_production_verified_at' => null,
                    'production_activated_at' => null,
                    'production_activated_by_user_id' => null,
                    'preflight_checked_at' => null,
                    'preflight_status' => null,
                    'certificate_not_before' => null,
                    'certificate_not_after' => null,
                    'certificate_fingerprint_sha256' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );

            $area = DB::table('venue_areas')
                ->where('business_id', $business->id)
                ->where('location_id', $location->id)
                ->where('name', 'Main Hall')
                ->first();

            if (! $area) {
                $areaId = (string) Str::ulid();
                DB::table('venue_areas')->insert([
                    'id' => $areaId,
                    'business_id' => $business->id,
                    'location_id' => $location->id,
                    'name' => 'Main Hall',
                    'sort_order' => 10,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $area = DB::table('venue_areas')->where('id', $areaId)->first();
            }

            foreach ([['T1', 2], ['T2', 4], ['T3', 4], ['T4', 6], ['VIP 1', 6]] as [$name, $capacity]) {
                $table = DB::table('venue_tables')
                    ->where('business_id', $business->id)
                    ->where('location_id', $location->id)
                    ->where('name', $name)
                    ->first();

                if (! $table) {
                    DB::table('venue_tables')->insert([
                        'id' => (string) Str::ulid(),
                        'business_id' => $business->id,
                        'location_id' => $location->id,
                        'venue_area_id' => $area->id,
                        'name' => $name,
                        'capacity' => $capacity,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            $categories = [];
            foreach ([
                ['Coffee', '#6F4E37', 10],
                ['Soft Drinks', '#5D8AA8', 20],
                ['Food', '#C0783E', 30],
            ] as [$name, $color, $sort]) {
                $category = DB::table('product_categories')
                    ->where('business_id', $business->id)
                    ->where('name', $name)
                    ->first();

                if (! $category) {
                    $id = (string) Str::ulid();
                    DB::table('product_categories')->insert([
                        'id' => $id,
                        'business_id' => $business->id,
                        'name' => $name,
                        'color' => $color,
                        'sort_order' => $sort,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $category = DB::table('product_categories')->where('id', $id)->first();
                }
                $categories[$name] = $category;
            }

            $products = [];
            foreach ([
                ['Espresso', 'COF-ESP', 'Coffee', '120.0000', '20.0000', 'bar'],
                ['Cappuccino', 'COF-CAP', 'Coffee', '180.0000', '20.0000', 'bar'],
                ['Coca-Cola', 'DRK-COKE', 'Soft Drinks', '200.0000', '20.0000', 'bar'],
                ['Club Sandwich', 'FOOD-CLUB', 'Food', '550.0000', '20.0000', 'kitchen'],
            ] as [$name, $sku, $categoryName, $price, $tax, $station]) {
                $product = DB::table('products')
                    ->where('business_id', $business->id)
                    ->where('sku', $sku)
                    ->first();

                if (! $product) {
                    $id = (string) Str::ulid();
                    DB::table('products')->insert([
                        'id' => $id,
                        'business_id' => $business->id,
                        'product_category_id' => $categories[$categoryName]->id,
                        'name' => $name,
                        'sku' => $sku,
                        'barcode' => null,
                        'sale_price' => $price,
                        'tax_rate' => $tax,
                        'preparation_station' => $station,
                        'unit_code' => 'C62',
                        'unit_label' => 'Cope',
                        'tracks_stock' => false,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $product = DB::table('products')->where('id', $id)->first();
                }
                $products[$sku] = $product;
            }

            $demoInvoices = [
                [
                    'suffix' => '001',
                    'customer' => 'Klient Demo',
                    'customer_tax' => null,
                    'items' => [
                        ['COF-ESP', 2],
                        ['DRK-COKE', 1],
                    ],
                    'method' => 'cash',
                ],
                [
                    'suffix' => '002',
                    'customer' => 'OpenAI Demo SHPK',
                    'customer_tax' => 'L99999999D',
                    'items' => [
                        ['COF-CAP', 2],
                        ['FOOD-CLUB', 1],
                    ],
                    'method' => 'card',
                ],
                [
                    'suffix' => '003',
                    'customer' => 'Walk-in Customer',
                    'customer_tax' => null,
                    'items' => [
                        ['FOOD-CLUB', 2],
                        ['COF-ESP', 2],
                    ],
                    'method' => 'cash',
                ],
            ];

            foreach ($demoInvoices as $offset => $demo) {
                $invoiceNumber = 'DEMO-INV-'.now()->format('Ymd').'-'.$demo['suffix'];
                if (DB::table('invoices')->where('business_id', $business->id)->where('number', $invoiceNumber)->exists()) {
                    continue;
                }

                $orderId = (string) Str::ulid();
                $orderNumber = 'DEMO-ORD-'.now()->format('Ymd').'-'.$demo['suffix'];

                $subtotal = 0.0;
                $taxTotal = 0.0;
                $grandTotal = 0.0;
                $orderLines = [];

                foreach ($demo['items'] as [$sku, $qty]) {
                    $product = $products[$sku];
                    $gross = (float) $product->sale_price * $qty;
                    $rate = (float) $product->tax_rate;
                    $net = $gross / (1 + ($rate / 100));
                    $tax = $gross - $net;

                    $subtotal += $net;
                    $taxTotal += $tax;
                    $grandTotal += $gross;
                    $orderLines[] = [$product, $qty, $net, $tax, $gross];
                }

                DB::table('orders')->insert([
                    'id' => $orderId,
                    'business_id' => $business->id,
                    'location_id' => $location->id,
                    'venue_table_id' => null,
                    'opened_by_user_id' => $users['waiter']->id,
                    'cancelled_by_user_id' => null,
                    'number' => $orderNumber,
                    'type' => 'counter',
                    'status' => 'paid',
                    'cancel_reason' => null,
                    'currency' => 'ALL',
                    'subtotal' => number_format($subtotal, 4, '.', ''),
                    'discount_total' => '0.0000',
                    'tax_total' => number_format($taxTotal, 4, '.', ''),
                    'grand_total' => number_format($grandTotal, 4, '.', ''),
                    'opened_at' => now()->subMinutes(30 + ($offset * 10)),
                    'closed_at' => now()->subMinutes(20 + ($offset * 10)),
                    'cancelled_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach ($orderLines as [$product, $qty, $net, $tax, $gross]) {
                    DB::table('order_items')->insert([
                        'id' => (string) Str::ulid(),
                        'business_id' => $business->id,
                        'order_id' => $orderId,
                        'product_id' => $product->id,
                        'voided_by_user_id' => null,
                        'product_name_snapshot' => $product->name,
                        'sku_snapshot' => $product->sku,
                        'quantity' => number_format($qty, 4, '.', ''),
                        'unit_price' => $product->sale_price,
                        'tax_rate' => $product->tax_rate,
                        'line_subtotal' => number_format($net, 4, '.', ''),
                        'line_tax' => number_format($tax, 4, '.', ''),
                        'line_total' => number_format($gross, 4, '.', ''),
                        'preparation_station' => $product->preparation_station,
                        'preparation_status' => 'served',
                        'note' => null,
                        'void_reason' => null,
                        'sent_at' => now()->subMinutes(28 + ($offset * 10)),
                        'preparing_at' => now()->subMinutes(27 + ($offset * 10)),
                        'prepared_at' => now()->subMinutes(25 + ($offset * 10)),
                        'served_at' => now()->subMinutes(24 + ($offset * 10)),
                        'voided_at' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $paymentId = (string) Str::ulid();
                DB::table('payments')->insert([
                    'id' => $paymentId,
                    'business_id' => $business->id,
                    'order_id' => $orderId,
                    'cash_session_id' => null,
                    'collected_by_user_id' => $users['cashier']->id,
                    'method' => $demo['method'],
                    'status' => 'completed',
                    'amount' => number_format($grandTotal, 4, '.', ''),
                    'amount_base' => number_format($grandTotal, 4, '.', ''),
                    'currency' => 'ALL',
                    'base_currency' => 'ALL',
                    'exchange_rate' => '1.0000000000',
                    'tendered_amount' => $demo['method'] === 'cash' ? number_format($grandTotal, 4, '.', '') : null,
                    'change_amount' => $demo['method'] === 'cash' ? '0.0000' : null,
                    'exchange_rate_snapshot' => null,
                    'idempotency_key' => 'demo-payment-'.$demo['suffix'],
                    'external_reference' => $demo['method'] === 'card' ? 'DEMO-POS-'.$demo['suffix'] : null,
                    'paid_at' => now()->subMinutes(20 + ($offset * 10)),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $invoiceId = (string) Str::ulid();
                $fiscalNumber = 'DEMO-FISC-'.now()->format('Y').'-'.$demo['suffix'];
                $nslf = 'DEMO-NSLF-'.$demo['suffix'].'-LOCAL';
                $nivf = 'DEMO-NIVF-'.$demo['suffix'].'-LOCAL';
                $qrPayload = 'DEMO|LOCAL_ONLY|invoice='.$invoiceNumber.'|nslf='.$nslf.'|nivf='.$nivf.'|total='.number_format($grandTotal, 2, '.', '').'|currency=ALL';

                DB::table('invoices')->insert([
                    'id' => $invoiceId,
                    'business_id' => $business->id,
                    'location_id' => $location->id,
                    'order_id' => $orderId,
                    'order_number_snapshot' => $orderNumber,
                    'created_by_user_id' => $users['cashier']->id,
                    'business_name_snapshot' => $business->name,
                    'business_legal_name_snapshot' => $business->legal_name,
                    'business_tax_number_snapshot' => $business->tax_number,
                    'location_name_snapshot' => $location->name,
                    'location_address_snapshot' => $location->address,
                    'fiscal_operator_code_snapshot' => 'OP-DEMO-CASHIER',
                    'fiscal_business_unit_code_snapshot' => 'BU-DEMO-001',
                    'fiscal_tcr_code_snapshot' => 'TCR-DEMO-001',
                    'number' => $invoiceNumber,
                    'status' => 'issued',
                    'fiscalization_status' => 'fiscalized',
                    'fiscal_invoice_type' => 'CASH',
                    'fiscal_invoice_number' => $fiscalNumber,
                    'fiscal_ordinal_number' => (int) $demo['suffix'],
                    'currency' => 'ALL',
                    'subtotal' => number_format($subtotal, 4, '.', ''),
                    'discount_total' => '0.0000',
                    'tax_total' => number_format($taxTotal, 4, '.', ''),
                    'grand_total' => number_format($grandTotal, 4, '.', ''),
                    'customer_name' => $demo['customer'],
                    'customer_tax_number' => $demo['customer_tax'],
                    'nslf' => $nslf,
                    'nivf' => $nivf,
                    'verification_url' => null,
                    'qr_payload' => $qrPayload,
                    'issued_at' => now()->subMinutes(20 + ($offset * 10)),
                    'fiscalized_at' => now()->subMinutes(19 + ($offset * 10)),
                    'fiscalization_attempts' => 1,
                    'fiscalization_error' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach ($orderLines as $index => [$product, $qty, $net, $tax, $gross]) {
                    DB::table('invoice_lines')->insert([
                        'id' => (string) Str::ulid(),
                        'business_id' => $business->id,
                        'invoice_id' => $invoiceId,
                        'position' => $index + 1,
                        'product_name_snapshot' => $product->name,
                        'sku_snapshot' => $product->sku,
                        'unit_code_snapshot' => 'C62',
                        'unit_label_snapshot' => 'Cope',
                        'quantity' => number_format($qty, 4, '.', ''),
                        'unit_price' => $product->sale_price,
                        'discount_percent' => '0.0000',
                        'tax_rate' => $product->tax_rate,
                        'line_subtotal' => number_format($net, 4, '.', ''),
                        'line_tax' => number_format($tax, 4, '.', ''),
                        'line_total' => number_format($gross, 4, '.', ''),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::table('invoice_payment_snapshots')->insert([
                    'id' => (string) Str::ulid(),
                    'business_id' => $business->id,
                    'invoice_id' => $invoiceId,
                    'position' => 1,
                    'method' => $demo['method'],
                    'method_label' => $demo['method'] === 'cash' ? 'Cash' : 'Card',
                    'amount' => number_format($grandTotal, 4, '.', ''),
                    'currency' => 'ALL',
                    'amount_base' => number_format($grandTotal, 4, '.', ''),
                    'base_currency' => 'ALL',
                    'exchange_rate' => '1.0000000000',
                    'external_reference' => $demo['method'] === 'card' ? 'DEMO-POS-'.$demo['suffix'] : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('business_settings')->updateOrInsert(
                ['business_id' => $business->id, 'key' => 'demo_seeded'],
                [
                    'id' => DB::table('business_settings')
                        ->where('business_id', $business->id)
                        ->where('key', 'demo_seeded')
                        ->value('id') ?? (string) Str::ulid(),
                    'value' => json_encode([
                        'local_only' => true,
                        'seeded_at' => now()->toISOString(),
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        });

        $this->command?->info('Local demo data ready.');
        $this->command?->line('Login: owner@hospitality.local');
        $this->command?->line('Password: Demo#Hospitality123!');
        $this->command?->warn('Fiscal codes, NSLF, NIVF, and QR payloads are DEMO / LOCAL ONLY.');
    }
}
