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
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

final class DemoHospitalitySeeder extends Seeder
{
    private const DEMO_EMAIL = 'owner@demo.local';
    private const DEMO_PASSWORD = 'Demo123!';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('DemoHospitalitySeeder may only run in local/testing environments.');
        }

        foreach ([
            'business_role_audits',
            'business_location_audits',
            'staff_invitations',
            'business_configuration_audits',
            'preparation_stations',
            'suppliers',
            'purchase_orders',
            'inventory_counts',
        ] as $requiredTable) {
            if (! Schema::hasTable($requiredTable)) {
                throw new RuntimeException(
                    "Demo data requires all current migrations. Missing table [{$requiredTable}]. Run: php artisan migrate"
                );
            }
        }

        $this->call([PermissionSeeder::class, RoleTemplateSeeder::class]);

        DB::transaction(function (): void {
            $business = Business::query()->updateOrCreate(
                ['tax_number' => 'DEMO-HOSPITALITY-001'],
                [
                    'name' => 'Marjo Demo Bistro',
                    'legal_name' => 'Marjo Hospitality Demo SH.P.K.',
                    'currency' => 'ALL',
                    'timezone' => 'Europe/Tirane',
                    'status' => 'active',
                ],
            );

            $location = Location::query()
                ->where('business_id', $business->id)
                ->whereIn('code', ['TIRANA-MAIN', 'BERLIN-MAIN'])
                ->orderByRaw("CASE WHEN code = 'TIRANA-MAIN' THEN 0 ELSE 1 END")
                ->first();

            if ($location) {
                $location->forceFill([
                    'name' => 'Blloku Flagship',
                    'code' => 'TIRANA-MAIN',
                    'type' => 'bar_cafe',
                    'address' => 'Rruga Ibrahim Rugova, Tirane · DEMO',
                    'is_active' => true,
                ])->save();
            } else {
                $location = Location::query()->create([
                    'business_id' => $business->id,
                    'name' => 'Blloku Flagship',
                    'code' => 'TIRANA-MAIN',
                    'type' => 'bar_cafe',
                    'address' => 'Rruga Ibrahim Rugova, Tirane · DEMO',
                    'is_active' => true,
                ]);
            }

            DB::table('locations')
                ->where('id', $location->id)
                ->update([
                    'fiscal_business_unit_code' => 'dm001bu001',
                    'updated_at' => now(),
                ]);

            $secondLocation = Location::query()->updateOrCreate(
                ['business_id' => $business->id, 'code' => 'TIRANA-ROOF'],
                [
                    'name' => 'Rooftop Terrace',
                    'type' => 'lounge',
                    'address' => 'Tirane Rooftop · DEMO',
                    'is_active' => true,
                ],
            );

            DB::table('locations')
                ->where('id', $secondLocation->id)
                ->update([
                    'fiscal_business_unit_code' => 'dm002bu002',
                    'updated_at' => now(),
                ]);

            app(ProvisionBusinessRoles::class)->handle($business);

            $roles = Role::query()
                ->where('business_id', $business->id)
                ->get()
                ->keyBy('slug');

            $owner = User::query()->updateOrCreate(
                ['email' => self::DEMO_EMAIL],
                [
                    'name' => 'Demo Owner',
                    'password' => Hash::make(self::DEMO_PASSWORD),
                    'email_verified_at' => now(),
                ],
            );

            $this->membership(
                $business,
                $owner,
                (string) $roles['owner']->id,
                'dm001op001',
            );

            $manager = $this->staff($business, (string) $roles['manager']->id, 'Demo Manager', 'manager@demo.local', 'dm002op002');
            $waiter = $this->staff($business, (string) $roles['waiter']->id, 'Demo Waiter', 'waiter@demo.local', 'dm003op003');
            $bartender = $this->staff($business, (string) $roles['bartender']->id, 'Demo Bartender', 'bartender@demo.local', 'dm004op004');
            $cashier = $this->staff($business, (string) $roles['cashier']->id, 'Demo Cashier', 'cashier@demo.local', 'dm005op005');
            $this->staff($business, (string) $roles['inventory']->id, 'Demo Inventory', 'inventory@demo.local', 'dm006op006');

            $this->membershipAudit($business, $waiter, $owner, $roles['waiter'], 'inactive', 'active', 'status_changed');

            foreach ([
                'receipt_footer' => 'Faleminderit! Ambient demonstrues lokal.',
                'service_charge_enabled' => false,
                'low_stock_alerts' => true,
            ] as $key => $value) {
                DB::table('business_settings')->updateOrInsert(
                    ['business_id' => $business->id, 'key' => $key],
                    [
                        'id' => $this->existingOrNewId('business_settings', ['business_id' => $business->id, 'key' => $key]),
                        'value' => json_encode($value, JSON_THROW_ON_ERROR),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }

            DB::table('fiscalization_profiles')->updateOrInsert(
                ['business_id' => $business->id],
                [
                    'id' => $this->existingOrNewId('fiscalization_profiles', ['business_id' => $business->id]),
                    'provider' => 'direct_dpt',
                    'environment' => 'test',
                    'status' => 'configured',
                    'software_code' => 'dm001sw001',
                    'certificate_secret_ref' => 'local/demo/fiscal-certificate',
                    'certificate_password_secret_ref' => 'local/demo/fiscal-certificate-password',
                    'is_issuer_in_vat' => true,
                    'endpoint' => config('fiscalization.test_endpoint') ?: 'https://efiskalizimi-app-test.tatime.gov.al/FiscalizationService-v3',
                    'last_verified_at' => now()->subMinutes(15),
                    'last_test_verified_at' => now()->subMinutes(15),
                    'preflight_checked_at' => now()->subMinutes(10),
                    'preflight_status' => 'warning',
                    'production_activated_at' => null,
                    'production_activated_by_user_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );

            $barStation = $this->station($business, 'Main Bar', 'bar', 10);
            $kitchenStation = $this->station($business, 'Kitchen Pass', 'kitchen', 20);

            $areas = [];
            foreach ([
                ['Indoor', 10],
                ['Terrace', 20],
                ['Bar Counter', 30],
            ] as [$name, $sort]) {
                $areas[$name] = $this->area($business, (string) $location->id, $name, $sort);
            }

            $tables = [];
            foreach ([
                ['T01', 'Indoor', 4],
                ['T02', 'Indoor', 4],
                ['T03', 'Indoor', 2],
                ['T04', 'Terrace', 4],
                ['T05', 'Terrace', 6],
                ['BAR-1', 'Bar Counter', 2],
            ] as [$name, $area, $capacity]) {
                $tables[$name] = $this->venueTable(
                    $business,
                    (string) $location->id,
                    $areas[$area],
                    $name,
                    $capacity,
                );
            }

            $categories = [];
            foreach ([
                ['Coffee', '#7C4D2A', 10],
                ['Cold Drinks', '#2563EB', 20],
                ['Kitchen', '#059669', 30],
            ] as [$name, $color, $sort]) {
                $categories[$name] = $this->category($business, $name, $color, $sort);
            }

            $products = [
                'COF-ESP' => $this->product($business, $categories['Coffee'], 'Espresso', 'COF-ESP', '120.0000', '20.0000', (string) $barStation, true),
                'COF-CAP' => $this->product($business, $categories['Coffee'], 'Cappuccino', 'COF-CAP', '180.0000', '20.0000', (string) $barStation, true),
                'DRK-WTR' => $this->product($business, $categories['Cold Drinks'], 'Still Water 0.5L', 'DRK-WTR', '100.0000', '20.0000', (string) $barStation, true),
                'DRK-COLA' => $this->product($business, $categories['Cold Drinks'], 'Cola', 'DRK-COLA', '180.0000', '20.0000', (string) $barStation, true),
                'KIT-BUR' => $this->product($business, $categories['Kitchen'], 'House Burger', 'KIT-BUR', '650.0000', '20.0000', (string) $kitchenStation, true),
                'KIT-SAL' => $this->product($business, $categories['Kitchen'], 'Caesar Salad', 'KIT-SAL', '480.0000', '20.0000', (string) $kitchenStation, true),
            ];

            foreach ([
                'COF-ESP' => ['65.0000', '20.0000'],
                'COF-CAP' => ['8.0000', '15.0000'],
                'DRK-WTR' => ['90.0000', '24.0000'],
                'DRK-COLA' => ['6.0000', '12.0000'],
                'KIT-BUR' => ['25.0000', '10.0000'],
                'KIT-SAL' => ['5.0000', '8.0000'],
            ] as $sku => [$onHand, $reorder]) {
                DB::table('inventory_stocks')->updateOrInsert(
                    [
                        'business_id' => $business->id,
                        'location_id' => $location->id,
                        'product_id' => $products[$sku],
                    ],
                    [
                        'id' => $this->existingOrNewId('inventory_stocks', [
                            'business_id' => $business->id,
                            'location_id' => $location->id,
                            'product_id' => $products[$sku],
                        ]),
                        'quantity_on_hand' => $onHand,
                        'reorder_level' => $reorder,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }

            $registerId = $this->register($business, (string) $location->id, 'Main Bar Till', 'MAIN-01', 'dm001tr001');
            $this->register($business, (string) $secondLocation->id, 'Rooftop Till', 'TILL-02', 'dm002tr002');

            $sessionId = $this->existingOrNewId('cash_sessions', [
                'business_id' => $business->id,
                'cash_register_id' => $registerId,
                'status' => 'open',
            ]);

            DB::table('cash_sessions')->updateOrInsert(
                [
                    'business_id' => $business->id,
                    'cash_register_id' => $registerId,
                    'status' => 'open',
                ],
                [
                    'id' => $sessionId,
                    'location_id' => $location->id,
                    'opened_by_user_id' => $cashier->id,
                    'base_currency' => 'ALL',
                    'opening_cash' => '10000.0000',
                    'open_idempotency_key' => 'demo-cash-session-open-main',
                    'open_request_snapshot' => json_encode(['demo' => true], JSON_THROW_ON_ERROR),
                    'opened_at' => now()->subHours(4),
                    'created_at' => now()->subHours(4),
                    'updated_at' => now(),
                ],
            );

            $openOrder = $this->order(
                $business,
                (string) $location->id,
                $tables['T01'],
                $waiter->id,
                'DEMO-OPEN-001',
                'open',
                '1100.0000',
                '220.0000',
                '1320.0000',
                now()->subMinutes(28),
                'demo-order-open-001',
            );

            $this->orderItem($business, $openOrder, $products['KIT-BUR'], 'House Burger', 'KIT-BUR', '1.0000', '650.0000', '541.6667', '108.3333', '650.0000', 'kitchen', 'preparing', now()->subMinutes(18), 'demo-open-item-burger');
            $this->orderItem($business, $openOrder, $products['COF-CAP'], 'Cappuccino', 'COF-CAP', '2.0000', '180.0000', '300.0000', '60.0000', '360.0000', 'bar', 'ready', now()->subMinutes(18), 'demo-open-item-cap');
            $this->orderItem($business, $openOrder, $products['DRK-COLA'], 'Cola', 'DRK-COLA', '1.0000', '180.0000', '150.0000', '30.0000', '180.0000', 'bar', 'pending', null, 'demo-open-item-cola');
            $this->orderItem($business, $openOrder, $products['DRK-WTR'], 'Still Water 0.5L', 'DRK-WTR', '1.3000', '100.0000', '108.3333', '21.6667', '130.0000', 'bar', 'pending', null, 'demo-open-item-water');

            $paidOrder = $this->order(
                $business,
                (string) $location->id,
                $tables['T02'],
                $waiter->id,
                'DEMO-PAID-001',
                'paid',
                '941.6667',
                '188.3333',
                '1130.0000',
                now()->subHours(2),
                'demo-order-paid-001',
            );

            $this->orderItem($business, $paidOrder, $products['KIT-BUR'], 'House Burger', 'KIT-BUR', '1.0000', '650.0000', '541.6667', '108.3333', '650.0000', 'kitchen', 'served', now()->subHours(2), 'demo-paid-item-burger');
            $this->orderItem($business, $paidOrder, $products['KIT-SAL'], 'Caesar Salad', 'KIT-SAL', '1.0000', '480.0000', '400.0000', '80.0000', '480.0000', 'kitchen', 'served', now()->subHours(2), 'demo-paid-item-salad');

            $paymentId = $this->existingOrNewId('payments', [
                'business_id' => $business->id,
                'idempotency_key' => 'demo-payment-paid-order-001',
            ]);

            DB::table('payments')->updateOrInsert(
                [
                    'business_id' => $business->id,
                    'idempotency_key' => 'demo-payment-paid-order-001',
                ],
                [
                    'id' => $paymentId,
                    'order_id' => $paidOrder,
                    'cash_session_id' => $sessionId,
                    'collected_by_user_id' => $cashier->id,
                    'method' => 'card',
                    'status' => 'completed',
                    'amount' => '1130.0000',
                    'currency' => 'ALL',
                    'amount_base' => '1130.0000',
                    'base_currency' => 'ALL',
                    'exchange_rate' => '1.0000000000',
                    'tendered_amount' => null,
                    'change_amount' => null,
                    'exchange_rate_snapshot' => json_encode(['source' => 'demo', 'rate' => 1], JSON_THROW_ON_ERROR),
                    'external_reference' => 'DEMO-POS-CARD-001',
                    'paid_at' => now()->subHours(2),
                    'created_at' => now()->subHours(2),
                    'updated_at' => now()->subHours(2),
                ],
            );

            $invoiceId = $this->existingOrNewId('invoices', [
                'business_id' => $business->id,
                'number' => 'INV-DEMO-2026-0001',
            ]);

            DB::table('invoices')->updateOrInsert(
                ['business_id' => $business->id, 'number' => 'INV-DEMO-2026-0001'],
                [
                    'id' => $invoiceId,
                    'location_id' => $location->id,
                    'order_id' => $paidOrder,
                    'order_number_snapshot' => 'DEMO-PAID-001',
                    'created_by_user_id' => $cashier->id,
                    'business_name_snapshot' => $business->name,
                    'business_legal_name_snapshot' => $business->legal_name,
                    'business_tax_number_snapshot' => $business->tax_number,
                    'location_name_snapshot' => 'Blloku Flagship',
                    'location_address_snapshot' => 'Rruga Ibrahim Rugova, Tirane · DEMO',
                    'fiscal_operator_code_snapshot' => 'dm005op005',
                    'fiscal_business_unit_code_snapshot' => 'dm001bu001',
                    'fiscal_tcr_code_snapshot' => 'dm001tr001',
                    'status' => 'issued',
                    'fiscalization_status' => 'fiscalized',
                    'fiscal_invoice_type' => 'CASH',
                    'fiscal_invoice_number' => 'DEMO-FISC-2026-0001',
                    'fiscal_ordinal_number' => 1,
                    'currency' => 'ALL',
                    'subtotal' => '941.6667',
                    'discount_total' => '0.0000',
                    'tax_total' => '188.3333',
                    'grand_total' => '1130.0000',
                    'customer_name' => 'Klient Demo',
                    'customer_tax_number' => null,
                    'nslf' => 'DEMO-NSLF-LOCAL-0000000001',
                    'nivf' => 'DEMO-NIVF-LOCAL-0000000001',
                    'verification_url' => null,
                    'qr_payload' => 'DEMO|LOCAL_ONLY|INV-DEMO-2026-0001|1130.00|ALL|Blloku Flagship',
                    'issued_at' => now()->subHours(2),
                    'fiscalized_at' => now()->subHours(2)->addSeconds(3),
                    'fiscalization_attempts' => 1,
                    'fiscalization_error' => null,
                    'created_at' => now()->subHours(2),
                    'updated_at' => now()->subHours(2),
                ],
            );

            DB::table('invoice_lines')->where('business_id', $business->id)->where('invoice_id', $invoiceId)->delete();
            $this->invoiceLine($business, $invoiceId, 1, 'House Burger', 'KIT-BUR', '1.0000', '650.0000', '541.6667', '108.3333', '650.0000');
            $this->invoiceLine($business, $invoiceId, 2, 'Caesar Salad', 'KIT-SAL', '1.0000', '480.0000', '400.0000', '80.0000', '480.0000');

            DB::table('invoice_payment_snapshots')
                ->where('business_id', $business->id)
                ->where('invoice_id', $invoiceId)
                ->delete();

            DB::table('invoice_payment_snapshots')->insert([
                'id' => (string) Str::ulid(),
                'business_id' => $business->id,
                'invoice_id' => $invoiceId,
                'position' => 1,
                'method' => 'card',
                'method_label' => 'Karte',
                'amount' => '1130.0000',
                'currency' => 'ALL',
                'amount_base' => '1130.0000',
                'base_currency' => 'ALL',
                'exchange_rate' => '1.0000000000',
                'external_reference' => 'DEMO-POS-CARD-001',
                'created_at' => now()->subHours(2),
                'updated_at' => now()->subHours(2),
            ]);

            DB::table('invoice_fiscalization_attempts')
                ->where('business_id', $business->id)
                ->where('invoice_id', $invoiceId)
                ->delete();

            DB::table('invoice_fiscalization_attempts')->insert([
                'id' => (string) Str::ulid(),
                'business_id' => $business->id,
                'invoice_id' => $invoiceId,
                'attempt_no' => 1,
                'provider' => 'direct_dpt',
                'environment' => 'test',
                'status' => 'succeeded',
                'retryable' => false,
                'request_id' => 'DEMO-REQUEST-001',
                'payload_hash' => hash('sha256', 'demo-invoice-payload'),
                'next_retry_at' => null,
                'http_status' => 200,
                'nslf' => 'DEMO-NSLF-LOCAL-0000000001',
                'nivf' => 'DEMO-NIVF-LOCAL-0000000001',
                'error_code' => null,
                'error_message' => null,
                'metadata' => json_encode(['demo' => true, 'local_only' => true], JSON_THROW_ON_ERROR),
                'started_at' => now()->subHours(2)->addSecond(),
                'completed_at' => now()->subHours(2)->addSeconds(3),
                'created_at' => now()->subHours(2),
                'updated_at' => now()->subHours(2),
            ]);

            foreach ([
                ['Rent', 'Monthly venue rent · DEMO', '120000.0000'],
                ['Supplies', 'Cleaning supplies · DEMO', '18500.0000'],
                ['Utilities', 'Internet and utilities · DEMO', '9600.0000'],
            ] as [$category, $description, $amount]) {
                DB::table('expenses')->updateOrInsert(
                    ['business_id' => $business->id, 'description' => $description],
                    [
                        'id' => $this->existingOrNewId('expenses', ['business_id' => $business->id, 'description' => $description]),
                        'location_id' => $location->id,
                        'created_by_user_id' => $owner->id,
                        'category' => $category,
                        'amount' => $amount,
                        'currency' => 'ALL',
                        'expense_date' => now()->toDateString(),
                        'status' => 'posted',
                        'idempotency_key' => 'demo-expense-'.Str::slug($category),
                        'request_snapshot' => json_encode(['demo' => true], JSON_THROW_ON_ERROR),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }

            $supplierId = $this->ulidFor(
                'suppliers',
                ['business_id' => $business->id, 'name' => 'Alba Coffee Supply'],
                [
                    'tax_number' => 'DEMO-SUPPLIER-NIPT',
                    'contact_name' => 'Dorian Demo',
                    'email' => 'supply.demo@example.test',
                    'phone' => '+355 69 000 0000',
                    'address' => 'Autostrada Tirane-Durres · DEMO',
                    'is_active' => true,
                ],
            );

            $purchaseOrderId = $this->existingOrNewId('purchase_orders', [
                'business_id' => $business->id,
                'number' => 'PO-DEMO-0001',
            ]);

            DB::table('purchase_orders')->updateOrInsert(
                ['business_id' => $business->id, 'number' => 'PO-DEMO-0001'],
                [
                    'id' => $purchaseOrderId,
                    'location_id' => $location->id,
                    'supplier_id' => $supplierId,
                    'created_by_user_id' => $owner->id,
                    'placed_by_user_id' => $manager->id,
                    'cancelled_by_user_id' => null,
                    'supplier_name_snapshot' => 'Alba Coffee Supply',
                    'supplier_tax_number_snapshot' => 'DEMO-SUPPLIER-NIPT',
                    'idempotency_key' => 'demo-po-create-0001',
                    'request_snapshot' => json_encode(['demo' => true, 'number' => 'PO-DEMO-0001'], JSON_THROW_ON_ERROR),
                    'status' => 'partially_received',
                    'currency' => 'ALL',
                    'total_cost' => '7500.0000',
                    'notes' => 'Demo weekly replenishment',
                    'ordered_at' => now()->subDay(),
                    'cancelled_at' => null,
                    'cancel_reason' => null,
                    'created_at' => now()->subDay(),
                    'updated_at' => now(),
                ],
            );

            $poItemId = $this->existingOrNewId('purchase_order_items', [
                'purchase_order_id' => $purchaseOrderId,
                'product_id' => $products['COF-ESP'],
            ]);

            DB::table('purchase_order_items')->updateOrInsert(
                [
                    'purchase_order_id' => $purchaseOrderId,
                    'product_id' => $products['COF-ESP'],
                ],
                [
                    'id' => $poItemId,
                    'business_id' => $business->id,
                    'product_name_snapshot' => 'Espresso',
                    'sku_snapshot' => 'COF-ESP',
                    'quantity_ordered' => '100.0000',
                    'quantity_received' => '40.0000',
                    'unit_cost' => '75.0000',
                    'line_total' => '7500.0000',
                    'created_at' => now()->subDay(),
                    'updated_at' => now(),
                ],
            );

            $receiptId = $this->existingOrNewId('goods_receipts', [
                'business_id' => $business->id,
                'number' => 'GRN-DEMO-0001',
            ]);

            DB::table('goods_receipts')->updateOrInsert(
                ['business_id' => $business->id, 'number' => 'GRN-DEMO-0001'],
                [
                    'id' => $receiptId,
                    'location_id' => $location->id,
                    'purchase_order_id' => $purchaseOrderId,
                    'received_by_user_id' => $owner->id,
                    'idempotency_key' => 'demo-goods-receipt-0001',
                    'request_snapshot' => json_encode(['demo' => true, 'quantity' => 40], JSON_THROW_ON_ERROR),
                    'note' => 'First partial delivery · DEMO',
                    'received_at' => now()->subHours(6),
                    'created_at' => now()->subHours(6),
                    'updated_at' => now()->subHours(6),
                ],
            );

            DB::table('goods_receipt_items')->updateOrInsert(
                [
                    'goods_receipt_id' => $receiptId,
                    'purchase_order_item_id' => $poItemId,
                ],
                [
                    'id' => $this->existingOrNewId('goods_receipt_items', [
                        'goods_receipt_id' => $receiptId,
                        'purchase_order_item_id' => $poItemId,
                    ]),
                    'business_id' => $business->id,
                    'product_id' => $products['COF-ESP'],
                    'quantity_received' => '40.0000',
                    'unit_cost_snapshot' => '75.0000',
                    'line_total' => '3000.0000',
                    'created_at' => now()->subHours(6),
                    'updated_at' => now()->subHours(6),
                ],
            );

            $this->purchaseEvent($business, $purchaseOrderId, $manager->id, 'placed', 'draft', 'ordered', ['demo' => true]);
            $this->purchaseEvent($business, $purchaseOrderId, $owner->id, 'goods_received', 'ordered', 'partially_received', ['goods_receipt_number' => 'GRN-DEMO-0001']);

            DB::table('inventory_movements')->updateOrInsert(
                [
                    'business_id' => $business->id,
                    'idempotency_key' => 'demo-purchase-receipt-movement-001',
                ],
                [
                    'id' => $this->existingOrNewId('inventory_movements', [
                        'business_id' => $business->id,
                        'idempotency_key' => 'demo-purchase-receipt-movement-001',
                    ]),
                    'location_id' => $location->id,
                    'product_id' => $products['COF-ESP'],
                    'created_by_user_id' => $owner->id,
                    'type' => 'purchase_receipt',
                    'quantity_delta' => '40.0000',
                    'reference_type' => 'goods_receipt',
                    'reference_id' => $receiptId,
                    'request_snapshot' => json_encode(['demo' => true], JSON_THROW_ON_ERROR),
                    'note' => 'Received against PO-DEMO-0001 · DEMO',
                    'occurred_at' => now()->subHours(6),
                    'created_at' => now()->subHours(6),
                    'updated_at' => now()->subHours(6),
                ],
            );
        });

        $this->command?->newLine();
        $this->command?->info('Demo hospitality workspace is ready.');
        $this->command?->line('URL: http://localhost:8080');
        $this->command?->line('Email: '.self::DEMO_EMAIL);
        $this->command?->line('Password: '.self::DEMO_PASSWORD);
        $this->command?->warn('Invoice QR / NSLF / NIVF values are DEMO LOCAL ONLY — not real DPT fiscal data.');
    }

    private function membership(Business $business, User $user, string $roleId, string $operatorCode): void
    {
        DB::table('business_user')->updateOrInsert(
            ['business_id' => $business->id, 'user_id' => $user->id],
            [
                'role_id' => $roleId,
                'fiscal_operator_code' => $operatorCode,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    private function staff(Business $business, string $roleId, string $name, string $email, string $operatorCode): User
    {
        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make(self::DEMO_PASSWORD),
                'email_verified_at' => now(),
            ],
        );

        $this->membership($business, $user, $roleId, $operatorCode);

        return $user;
    }

    private function membershipAudit(Business $business, User $target, User $actor, Role $role, string $previousStatus, string $newStatus, string $action): void
    {
        $exists = DB::table('business_membership_audits')
            ->where('business_id', $business->id)
            ->where('target_user_id', $target->id)
            ->where('action', $action)
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('business_membership_audits')->insert([
            'id' => (string) Str::ulid(),
            'business_id' => $business->id,
            'target_user_id' => $target->id,
            'performed_by_user_id' => $actor->id,
            'previous_role_id' => $role->id,
            'previous_role_name' => $role->name,
            'previous_role_slug' => $role->slug,
            'previous_status' => $previousStatus,
            'new_role_id' => $role->id,
            'new_role_name' => $role->name,
            'new_role_slug' => $role->slug,
            'new_status' => $newStatus,
            'action' => $action,
            'performed_at' => now()->subHour(),
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);
    }

    private function station(Business $business, string $name, string $code, int $sort): string
    {
        return $this->ulidFor(
            'preparation_stations',
            ['business_id' => $business->id, 'code' => $code],
            ['name' => $name, 'sort_order' => $sort, 'is_active' => true],
        );
    }

    private function category(Business $business, string $name, string $color, int $sort): string
    {
        return $this->ulidFor(
            'product_categories',
            ['business_id' => $business->id, 'name' => $name],
            ['color' => $color, 'sort_order' => $sort, 'is_active' => true],
        );
    }

    private function product(Business $business, string $categoryId, string $name, string $sku, string $price, string $tax, string $stationId, bool $track): string
    {
        $stationCode = DB::table('preparation_stations')->where('id', $stationId)->value('code');

        return $this->ulidFor(
            'products',
            ['business_id' => $business->id, 'sku' => $sku],
            [
                'product_category_id' => $categoryId,
                'name' => $name,
                'barcode' => null,
                'sale_price' => $price,
                'tax_rate' => $tax,
                'preparation_station' => $stationCode,
                'unit_code' => 'C62',
                'unit_label' => 'Cope',
                'tracks_stock' => $track,
                'is_active' => true,
            ],
        );
    }

    private function area(Business $business, string $locationId, string $name, int $sort): string
    {
        return $this->ulidFor(
            'venue_areas',
            ['business_id' => $business->id, 'location_id' => $locationId, 'name' => $name],
            ['sort_order' => $sort, 'is_active' => true],
        );
    }

    private function venueTable(Business $business, string $locationId, string $areaId, string $name, int $capacity): string
    {
        return $this->ulidFor(
            'venue_tables',
            ['location_id' => $locationId, 'name' => $name],
            [
                'business_id' => $business->id,
                'venue_area_id' => $areaId,
                'capacity' => $capacity,
                'is_active' => true,
            ],
        );
    }

    private function register(Business $business, string $locationId, string $name, string $code, string $tcr): string
    {
        $id = $this->ulidFor(
            'cash_registers',
            ['business_id' => $business->id, 'code' => $code],
            [
                'location_id' => $locationId,
                'name' => $name,
                'fiscal_tcr_code' => $tcr,
                'is_active' => true,
            ],
        );

        return $id;
    }

    private function order(
        Business $business,
        string $locationId,
        ?string $tableId,
        int $userId,
        string $number,
        string $status,
        string $subtotal,
        string $tax,
        string $total,
        mixed $openedAt,
        string $idempotencyKey,
    ): string {
        $id = $this->existingOrNewId('orders', ['business_id' => $business->id, 'number' => $number]);

        DB::table('orders')->updateOrInsert(
            ['business_id' => $business->id, 'number' => $number],
            [
                'id' => $id,
                'location_id' => $locationId,
                'venue_table_id' => $tableId,
                'opened_by_user_id' => $userId,
                'idempotency_key' => $idempotencyKey,
                'request_snapshot' => json_encode(['demo' => true, 'number' => $number], JSON_THROW_ON_ERROR),
                'type' => 'table',
                'status' => $status,
                'currency' => 'ALL',
                'subtotal' => $subtotal,
                'discount_total' => '0.0000',
                'tax_total' => $tax,
                'grand_total' => $total,
                'opened_at' => $openedAt,
                'closed_at' => $status === 'paid' ? now()->subHours(2) : null,
                'created_at' => $openedAt,
                'updated_at' => now(),
            ],
        );

        return $id;
    }

    private function orderItem(
        Business $business,
        string $orderId,
        string $productId,
        string $name,
        string $sku,
        string $qty,
        string $unitPrice,
        string $subtotal,
        string $tax,
        string $total,
        string $station,
        string $prepStatus,
        mixed $sentAt,
        string $idempotencyKey,
    ): void {
        DB::table('order_items')->updateOrInsert(
            ['business_id' => $business->id, 'idempotency_key' => $idempotencyKey],
            [
                'id' => $this->existingOrNewId('order_items', ['business_id' => $business->id, 'idempotency_key' => $idempotencyKey]),
                'order_id' => $orderId,
                'product_id' => $productId,
                'product_name_snapshot' => $name,
                'sku_snapshot' => $sku,
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'tax_rate' => '20.0000',
                'line_subtotal' => $subtotal,
                'line_tax' => $tax,
                'line_total' => $total,
                'preparation_station' => $station,
                'preparation_status' => $prepStatus,
                'note' => null,
                'request_snapshot' => json_encode(['demo' => true], JSON_THROW_ON_ERROR),
                'sent_at' => $sentAt,
                'preparing_at' => $prepStatus === 'preparing' ? now()->subMinutes(15) : null,
                'prepared_at' => in_array($prepStatus, ['ready', 'served'], true) ? now()->subMinutes(5) : null,
                'served_at' => $prepStatus === 'served' ? now()->subHours(2) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    private function invoiceLine(Business $business, string $invoiceId, int $position, string $name, string $sku, string $qty, string $unitPrice, string $subtotal, string $tax, string $total): void
    {
        DB::table('invoice_lines')->insert([
            'id' => (string) Str::ulid(),
            'business_id' => $business->id,
            'invoice_id' => $invoiceId,
            'position' => $position,
            'product_name_snapshot' => $name,
            'sku_snapshot' => $sku,
            'unit_code_snapshot' => 'C62',
            'unit_label_snapshot' => 'Cope',
            'quantity' => $qty,
            'unit_price' => $unitPrice,
            'discount_percent' => '0.0000',
            'tax_rate' => '20.0000',
            'line_subtotal' => $subtotal,
            'line_tax' => $tax,
            'line_total' => $total,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function purchaseEvent(Business $business, string $purchaseOrderId, int $actorUserId, string $event, ?string $previous, string $next, array $metadata): void
    {
        $exists = DB::table('purchase_order_events')
            ->where('business_id', $business->id)
            ->where('purchase_order_id', $purchaseOrderId)
            ->where('event', $event)
            ->where('new_status', $next)
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('purchase_order_events')->insert([
            'id' => (string) Str::ulid(),
            'business_id' => $business->id,
            'purchase_order_id' => $purchaseOrderId,
            'actor_user_id' => $actorUserId,
            'event' => $event,
            'previous_status' => $previous,
            'new_status' => $next,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'occurred_at' => now()->subHours($event === 'placed' ? 24 : 6),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ulidFor(string $table, array $identity, array $values): string
    {
        $id = $this->existingOrNewId($table, $identity);

        DB::table($table)->updateOrInsert(
            $identity,
            [
                'id' => $id,
                ...$values,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        return $id;
    }

    private function existingOrNewId(string $table, array $identity): string
    {
        return (string) (DB::table($table)->where($identity)->value('id') ?: Str::ulid());
    }
}
