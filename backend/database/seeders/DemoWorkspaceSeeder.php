<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\User;
use App\Services\Authorization\ProvisionBusinessRoles;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

final class DemoWorkspaceSeeder extends Seeder
{
    public const OWNER_EMAIL = 'demo.owner@hospitality.local';
    public const OWNER_PASSWORD = 'Demo#Hospitality2026!';

    private Business $business;
    private User $owner;
    private array $users = [];
    private array $roles = [];
    private array $locations = [];
    private array $categories = [];
    private array $products = [];
    private array $areas = [];
    private array $tables = [];
    private array $registers = [];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('DemoWorkspaceSeeder is restricted to local/testing environments.');
        }

        $this->assertSchemaReady();

        DB::transaction(function (): void {
            $this->call([PermissionSeeder::class, RoleTemplateSeeder::class]);
            $this->seedIdentityAndAccess();
            $this->seedLocations();
            $this->seedStationsCatalogAndVenue();
            $this->seedInventory();
            $this->seedCashAndSales();
            $this->seedFinanceAndInvoices();
            $this->seedPurchasing();
            $this->seedInventoryControl();
            $this->seedInvitationsAndAudits();
            $this->seedSettings();
        }, 3);

        $this->command?->newLine();
        $this->command?->info('Hospitality demo workspace is ready.');
        $this->command?->line('Login email: '.self::OWNER_EMAIL);
        $this->command?->line('Login password: '.self::OWNER_PASSWORD);
        $this->command?->line('Business: '.$this->business->name);
        $this->command?->line('Primary location: '.$this->locations['main']->name);
        $this->command?->warn('Local/test credentials only. Never use this demo password in production.');
    }

    private function assertSchemaReady(): void
    {
        $required = [
            'business_role_audits',
            'business_location_audits',
            'staff_invitations',
            'staff_invitation_events',
            'business_configuration_audits',
            'suppliers',
            'purchase_orders',
            'goods_receipts',
            'inventory_transfers',
            'inventory_counts',
            'preparation_stations',
        ];

        $missing = collect($required)->reject(fn (string $table): bool => Schema::hasTable($table))->values();

        if ($missing->isNotEmpty()) {
            throw new RuntimeException(
                'Demo schema is not ready. Run php artisan migrate first. Missing tables: '.$missing->join(', ')
            );
        }
    }

    private function seedIdentityAndAccess(): void
    {
        $this->business = Business::query()
            ->where('tax_number', 'L12345678A')
            ->orWhere('tax_number', 'DEMO-TAX-2026')
            ->orWhere('name', 'Hospitality Demo Lab')
            ->first();

        if ($this->business) {
            $this->business->forceFill([
                'name' => 'Hospitality Demo Lab',
                'legal_name' => 'Hospitality Demo Lab GmbH',
                'tax_number' => 'L12345678A',
                'currency' => 'EUR',
                'timezone' => 'Europe/Berlin',
                'status' => 'active',
            ])->save();
        } else {
            $this->business = Business::query()->create([
                'name' => 'Hospitality Demo Lab',
                'legal_name' => 'Hospitality Demo Lab GmbH',
                'tax_number' => 'L12345678A',
                'currency' => 'EUR',
                'timezone' => 'Europe/Berlin',
                'status' => 'active',
            ]);
        }

        $this->roles = app(ProvisionBusinessRoles::class)
            ->handle($this->business)
            ->all();

        $people = [
            'owner' => ['Demo Owner', self::OWNER_EMAIL, 'owner'],
            'manager' => ['Mia Manager', 'demo.manager@hospitality.local', 'manager'],
            'waiter' => ['Will Waiter', 'demo.waiter@hospitality.local', 'waiter'],
            'bartender' => ['Bella Bartender', 'demo.bartender@hospitality.local', 'bartender'],
            'cashier' => ['Chris Cashier', 'demo.cashier@hospitality.local', 'cashier'],
            'inventory' => ['Ivy Inventory', 'demo.inventory@hospitality.local', 'inventory'],
            'finance' => ['Finn Finance', 'demo.finance@hospitality.local', 'finance'],
        ];

        foreach ($people as $key => [$name, $email, $roleSlug]) {
            $user = User::query()->updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => self::OWNER_PASSWORD,
                    'email_verified_at' => now(),
                ],
            );

            $role = $this->roles[$roleSlug] ?? null;
            if (! $role) {
                throw new RuntimeException("Missing business role template: {$roleSlug}");
            }

            DB::table('business_user')->updateOrInsert(
                [
                    'business_id' => $this->business->getKey(),
                    'user_id' => $user->getKey(),
                ],
                $this->filtered('business_user', [
                    'role_id' => $role->getKey(),
                    'status' => 'active',
                    'fiscal_operator_code' => match ($key) {
                        'owner' => 'dm101op001',
                        'manager' => 'dm102op002',
                        'waiter' => 'dm103op003',
                        'bartender' => 'dm104op004',
                        'cashier' => 'dm105op005',
                        'inventory' => 'dm106op006',
                        'finance' => 'dm107op007',
                        default => 'dm199op999',
                    },
                    'created_at' => now(),
                    'updated_at' => now(),
                ]),
            );

            $this->users[$key] = $user;
        }

        $this->owner = $this->users['owner'];
    }

    private function seedLocations(): void
    {
        $this->locations['main'] = $this->upsertUlid('locations', [
            'business_id' => $this->business->getKey(),
            'code' => 'DEMO-MAIN',
        ], [
            'name' => 'Alexanderplatz Café',
            'type' => 'bar_cafe',
            'address' => 'Alexanderplatz 1, Berlin',
            'fiscal_business_unit_code' => 'dm001bu001',
            'is_active' => true,
        ]);

        $this->locations['rooftop'] = $this->upsertUlid('locations', [
            'business_id' => $this->business->getKey(),
            'code' => 'DEMO-ROOF',
        ], [
            'name' => 'Rooftop Bar',
            'type' => 'bar',
            'address' => 'Demo Terrace, Berlin',
            'fiscal_business_unit_code' => 'dm002bu002',
            'is_active' => true,
        ]);

        $this->locations['seasonal'] = $this->upsertUlid('locations', [
            'business_id' => $this->business->getKey(),
            'code' => 'DEMO-SEASONAL',
        ], [
            'name' => 'Seasonal Garden',
            'type' => 'cafe',
            'address' => 'Demo Garden, Berlin',
            'fiscal_business_unit_code' => null,
            'is_active' => false,
        ]);
    }

    private function seedStationsCatalogAndVenue(): void
    {
        $this->upsertUlid('preparation_stations', [
            'business_id' => $this->business->getKey(),
            'code' => 'bar',
        ], [
            'name' => 'Bar',
            'sort_order' => 10,
            'is_active' => true,
        ]);

        $this->upsertUlid('preparation_stations', [
            'business_id' => $this->business->getKey(),
            'code' => 'kitchen',
        ], [
            'name' => 'Kitchen',
            'sort_order' => 20,
            'is_active' => true,
        ]);

        $this->categories['coffee'] = $this->upsertUlid('product_categories', [
            'business_id' => $this->business->getKey(),
            'name' => 'Coffee',
        ], ['color' => '#7A5137', 'sort_order' => 10, 'is_active' => true]);

        $this->categories['drinks'] = $this->upsertUlid('product_categories', [
            'business_id' => $this->business->getKey(),
            'name' => 'Cold Drinks',
        ], ['color' => '#3C82F6', 'sort_order' => 20, 'is_active' => true]);

        $this->categories['food'] = $this->upsertUlid('product_categories', [
            'business_id' => $this->business->getKey(),
            'name' => 'Food',
        ], ['color' => '#E29B42', 'sort_order' => 30, 'is_active' => true]);

        $products = [
            'espresso' => ['Espresso', 'DEMO-ESP', '2.80', '20.0000', 'bar', false, 'C62', 'pcs', 'coffee'],
            'cappuccino' => ['Cappuccino', 'DEMO-CAP', '4.20', '20.0000', 'bar', false, 'C62', 'pcs', 'coffee'],
            'water' => ['Sparkling Water', 'DEMO-WATER', '3.50', '20.0000', 'bar', true, 'C62', 'bottle', 'drinks'],
            'juice' => ['Orange Juice', 'DEMO-JUICE', '4.80', '20.0000', 'bar', true, 'C62', 'bottle', 'drinks'],
            'croissant' => ['Butter Croissant', 'DEMO-CROIS', '3.90', '20.0000', 'kitchen', true, 'C62', 'pcs', 'food'],
            'burger' => ['House Burger', 'DEMO-BURGER', '13.50', '20.0000', 'kitchen', true, 'C62', 'pcs', 'food'],
        ];

        foreach ($products as $key => [$name, $sku, $price, $tax, $station, $tracks, $unitCode, $unitLabel, $category]) {
            $this->products[$key] = $this->upsertUlid('products', [
                'business_id' => $this->business->getKey(),
                'sku' => $sku,
            ], [
                'product_category_id' => $this->categories[$category]->id,
                'name' => $name,
                'barcode' => null,
                'unit_code' => $unitCode,
                'unit_label' => $unitLabel,
                'sale_price' => $price,
                'tax_rate' => $tax,
                'preparation_station' => $station,
                'tracks_stock' => $tracks,
                'is_active' => true,
            ]);
        }

        $this->areas['indoor'] = $this->upsertUlid('venue_areas', [
            'business_id' => $this->business->getKey(),
            'location_id' => $this->locations['main']->id,
            'name' => 'Indoor',
        ], ['sort_order' => 10, 'is_active' => true]);

        $this->areas['terrace'] = $this->upsertUlid('venue_areas', [
            'business_id' => $this->business->getKey(),
            'location_id' => $this->locations['main']->id,
            'name' => 'Terrace',
        ], ['sort_order' => 20, 'is_active' => true]);

        $this->areas['rooftop'] = $this->upsertUlid('venue_areas', [
            'business_id' => $this->business->getKey(),
            'location_id' => $this->locations['rooftop']->id,
            'name' => 'Sky Deck',
        ], ['sort_order' => 10, 'is_active' => true]);

        foreach ([
            'T1' => ['main', 'indoor', 2],
            'T2' => ['main', 'indoor', 4],
            'T3' => ['main', 'terrace', 4],
            'T4' => ['main', 'terrace', 6],
            'R1' => ['rooftop', 'rooftop', 4],
            'R2' => ['rooftop', 'rooftop', 6],
        ] as $name => [$locationKey, $areaKey, $capacity]) {
            $this->tables[$name] = $this->upsertUlid('venue_tables', [
                'location_id' => $this->locations[$locationKey]->id,
                'name' => $name,
            ], [
                'business_id' => $this->business->getKey(),
                'venue_area_id' => $this->areas[$areaKey]->id,
                'capacity' => $capacity,
                'is_active' => true,
            ]);
        }

        $this->registers['main'] = $this->upsertUlid('cash_registers', [
            'business_id' => $this->business->getKey(),
            'code' => 'DEMO-MAIN-TILL',
        ], [
            'location_id' => $this->locations['main']->id,
            'name' => 'Main Till',
            'fiscal_tcr_code' => 'dm011tc001',
            'is_active' => true,
        ]);

        $this->registers['bar'] = $this->upsertUlid('cash_registers', [
            'business_id' => $this->business->getKey(),
            'code' => 'DEMO-BAR-TILL',
        ], [
            'location_id' => $this->locations['main']->id,
            'name' => 'Bar Till',
            'fiscal_tcr_code' => 'dm012tc002',
            'is_active' => true,
        ]);

        $this->registers['rooftop'] = $this->upsertUlid('cash_registers', [
            'business_id' => $this->business->getKey(),
            'code' => 'DEMO-ROOF-TILL',
        ], [
            'location_id' => $this->locations['rooftop']->id,
            'name' => 'Rooftop Till',
            'fiscal_tcr_code' => 'dm013tc003',
            'is_active' => true,
        ]);
    }

    private function seedInventory(): void
    {
        $mainStock = [
            'water' => ['28.0000', '12.0000'],
            'juice' => ['7.0000', '10.0000'],
            'croissant' => ['0.0000', '8.0000'],
            'burger' => ['18.0000', '6.0000'],
        ];

        $roofStock = [
            'water' => ['14.0000', '8.0000'],
            'juice' => ['11.0000', '6.0000'],
            'croissant' => ['5.0000', '4.0000'],
            'burger' => ['8.0000', '4.0000'],
        ];

        foreach ([['main', $mainStock], ['rooftop', $roofStock]] as [$locationKey, $stocks]) {
            foreach ($stocks as $productKey => [$onHand, $reorder]) {
                $stock = $this->upsertUlid('inventory_stocks', [
                    'business_id' => $this->business->getKey(),
                    'location_id' => $this->locations[$locationKey]->id,
                    'product_id' => $this->products[$productKey]->id,
                ], [
                    'quantity_on_hand' => $onHand,
                    'reorder_level' => $reorder,
                ]);

                $this->upsertUlid('inventory_movements', [
                    'business_id' => $this->business->getKey(),
                    'reference_type' => 'demo_seed',
                    'reference_id' => 'stock-'.$locationKey.'-'.$productKey,
                ], [
                    'location_id' => $this->locations[$locationKey]->id,
                    'product_id' => $this->products[$productKey]->id,
                    'created_by_user_id' => $this->owner->id,
                    'type' => 'adjustment',
                    'quantity_delta' => $onHand,
                    'idempotency_key' => 'demo-stock-'.$locationKey.'-'.$productKey,
                    'request_snapshot' => json_encode(['demo' => true, 'stock_id' => $stock->id], JSON_THROW_ON_ERROR),
                    'note' => 'Demo opening stock',
                    'occurred_at' => now()->subDays(5),
                ]);
            }
        }
    }

    private function seedCashAndSales(): void
    {
        $closedSession = $this->upsertUlid('cash_sessions', [
            'business_id' => $this->business->getKey(),
            'cash_register_id' => $this->registers['main']->id,
            'status' => 'closed',
        ], [
            'location_id' => $this->locations['main']->id,
            'opened_by_user_id' => $this->users['cashier']->id,
            'closed_by_user_id' => $this->users['cashier']->id,
            'open_idempotency_key' => 'demo-closed-session-open',
            'open_request_snapshot' => json_encode(['demo' => true], JSON_THROW_ON_ERROR),
            'base_currency' => $this->business->currency,
            'opening_cash' => '150.0000',
            'expected_cash' => '284.0000',
            'counted_cash' => '282.5000',
            'cash_difference' => '-1.5000',
            'opened_at' => now()->subDay()->setTime(8, 0),
            'closed_at' => now()->subDay()->setTime(23, 0),
            'closing_note' => 'Demo closed shift',
            'close_idempotency_key' => 'demo-closed-session-close',
            'close_request_snapshot' => json_encode(['demo' => true], JSON_THROW_ON_ERROR),
        ]);

        $this->upsertUlid('cash_sessions', [
            'business_id' => $this->business->getKey(),
            'cash_register_id' => $this->registers['bar']->id,
            'status' => 'open',
        ], [
            'location_id' => $this->locations['main']->id,
            'opened_by_user_id' => $this->users['cashier']->id,
            'open_idempotency_key' => 'demo-open-session',
            'open_request_snapshot' => json_encode(['demo' => true], JSON_THROW_ON_ERROR),
            'base_currency' => $this->business->currency,
            'opening_cash' => '100.0000',
            'opened_at' => now()->subHours(3),
        ]);

        $paidOrder = $this->upsertUlid('orders', [
            'business_id' => $this->business->getKey(),
            'number' => 'DEMO-ORD-001',
        ], [
            'location_id' => $this->locations['main']->id,
            'venue_table_id' => $this->tables['T3']->id,
            'opened_by_user_id' => $this->users['waiter']->id,
            'idempotency_key' => 'demo-order-001',
            'request_snapshot' => json_encode(['demo' => true], JSON_THROW_ON_ERROR),
            'type' => 'table',
            'status' => 'paid',
            'currency' => $this->business->currency,
            'subtotal' => '18.2500',
            'discount_total' => '1.9000',
            'tax_total' => '3.6500',
            'grand_total' => '20.0000',
            'discount_reason' => 'Demo loyalty discount',
            'discount_applied_by_user_id' => $this->users['manager']->id,
            'discount_applied_at' => now()->subDay()->setTime(18, 35),
            'opened_at' => now()->subDay()->setTime(18, 0),
            'closed_at' => now()->subDay()->setTime(19, 0),
        ]);

        $this->seedOrderItem($paidOrder->id, $this->products['burger']->id, 'House Burger', 'DEMO-BURGER', '1.0000', '13.5000', 'kitchen', 'served', 'demo-item-paid-burger', now()->subDay()->setTime(18, 5));
        $this->seedOrderItem($paidOrder->id, $this->products['cappuccino']->id, 'Cappuccino', 'DEMO-CAP', '2.0000', '4.2000', 'bar', 'served', 'demo-item-paid-cap', now()->subDay()->setTime(18, 5));

        $payment = $this->upsertUlid('payments', [
            'business_id' => $this->business->getKey(),
            'idempotency_key' => 'demo-payment-001',
        ], [
            'order_id' => $paidOrder->id,
            'cash_session_id' => $closedSession->id,
            'collected_by_user_id' => $this->users['cashier']->id,
            'method' => 'cash',
            'status' => 'completed',
            'amount' => '20.0000',
            'amount_base' => '20.0000',
            'currency' => $this->business->currency,
            'base_currency' => $this->business->currency,
            'exchange_rate' => '1.0000000000',
            'tendered_amount' => '20.0000',
            'change_amount' => '0.0000',
            'exchange_rate_snapshot' => json_encode(['rate' => 1, 'demo' => true], JSON_THROW_ON_ERROR),
            'paid_at' => now()->subDay()->setTime(19, 0),
        ]);

        $refundOrder = $this->upsertUlid('orders', [
            'business_id' => $this->business->getKey(),
            'number' => 'DEMO-ORD-005',
        ], [
            'location_id' => $this->locations['main']->id,
            'venue_table_id' => null,
            'opened_by_user_id' => $this->users['waiter']->id,
            'idempotency_key' => 'demo-order-005',
            'request_snapshot' => json_encode(['demo' => true], JSON_THROW_ON_ERROR),
            'type' => 'counter',
            'status' => 'partially_refunded',
            'currency' => $this->business->currency,
            'subtotal' => '6.9167',
            'discount_total' => '0.0000',
            'tax_total' => '1.3833',
            'grand_total' => '8.3000',
            'opened_at' => now()->subHours(6),
            'closed_at' => now()->subHours(5),
        ]);

        $this->seedOrderItem($refundOrder->id, $this->products['water']->id, 'Sparkling Water', 'DEMO-WATER', '1.0000', '3.5000', 'bar', 'served', 'demo-item-refund-water', now()->subHours(6)->addMinutes(2));
        $this->seedOrderItem($refundOrder->id, $this->products['juice']->id, 'Orange Juice', 'DEMO-JUICE', '1.0000', '4.8000', 'bar', 'served', 'demo-item-refund-juice', now()->subHours(6)->addMinutes(2));

        $refundPayment = $this->upsertUlid('payments', [
            'business_id' => $this->business->getKey(),
            'idempotency_key' => 'demo-payment-refund-source',
        ], [
            'order_id' => $refundOrder->id,
            'cash_session_id' => $closedSession->id,
            'collected_by_user_id' => $this->users['cashier']->id,
            'method' => 'card',
            'status' => 'completed',
            'amount' => '8.3000',
            'amount_base' => '8.3000',
            'currency' => $this->business->currency,
            'base_currency' => $this->business->currency,
            'exchange_rate' => '1.0000000000',
            'tendered_amount' => null,
            'change_amount' => null,
            'exchange_rate_snapshot' => json_encode(['rate' => 1, 'demo' => true], JSON_THROW_ON_ERROR),
            'paid_at' => now()->subHours(5),
        ]);

        $this->upsertUlid('payment_refunds', [
            'business_id' => $this->business->getKey(),
            'idempotency_key' => 'demo-refund-001',
        ], [
            'payment_id' => $refundPayment->id,
            'cash_session_id' => $closedSession->id,
            'refunded_by_user_id' => $this->users['cashier']->id,
            'amount' => '2.0000',
            'amount_base' => '2.0000',
            'currency' => $this->business->currency,
            'base_currency' => $this->business->currency,
            'exchange_rate' => '1.0000000000',
            'reason' => 'Demo partial refund',
            'status' => 'completed',
            'refunded_at' => now()->subHours(4),
        ]);

        $openOrder = $this->upsertUlid('orders', [
            'business_id' => $this->business->getKey(),
            'number' => 'DEMO-ORD-002',
        ], [
            'location_id' => $this->locations['main']->id,
            'venue_table_id' => $this->tables['T1']->id,
            'opened_by_user_id' => $this->users['waiter']->id,
            'idempotency_key' => 'demo-order-002',
            'request_snapshot' => json_encode(['demo' => true], JSON_THROW_ON_ERROR),
            'type' => 'table',
            'status' => 'open',
            'currency' => $this->business->currency,
            'subtotal' => '12.6000',
            'discount_total' => '0.0000',
            'tax_total' => '2.1000',
            'grand_total' => '12.6000',
            'opened_at' => now()->subMinutes(25),
        ]);

        $this->seedOrderItem($openOrder->id, $this->products['espresso']->id, 'Espresso', 'DEMO-ESP', '2.0000', '2.8000', 'bar', 'preparing', 'demo-item-open-espresso', now()->subMinutes(20));
        $this->seedOrderItem($openOrder->id, $this->products['croissant']->id, 'Butter Croissant', 'DEMO-CROIS', '1.0000', '3.9000', 'kitchen', 'sent', 'demo-item-open-croissant', now()->subMinutes(18));

        $dueOrder = $this->upsertUlid('orders', [
            'business_id' => $this->business->getKey(),
            'number' => 'DEMO-ORD-003',
        ], [
            'location_id' => $this->locations['main']->id,
            'venue_table_id' => $this->tables['T2']->id,
            'opened_by_user_id' => $this->users['waiter']->id,
            'idempotency_key' => 'demo-order-003',
            'request_snapshot' => json_encode(['demo' => true], JSON_THROW_ON_ERROR),
            'type' => 'table',
            'status' => 'payment_due',
            'currency' => $this->business->currency,
            'subtotal' => '8.3000',
            'discount_total' => '0.0000',
            'tax_total' => '1.3833',
            'grand_total' => '8.3000',
            'opened_at' => now()->subMinutes(55),
        ]);

        $this->seedOrderItem($dueOrder->id, $this->products['water']->id, 'Sparkling Water', 'DEMO-WATER', '1.0000', '3.5000', 'bar', 'served', 'demo-item-due-water', now()->subMinutes(50));
        $this->seedOrderItem($dueOrder->id, $this->products['juice']->id, 'Orange Juice', 'DEMO-JUICE', '1.0000', '4.8000', 'bar', 'served', 'demo-item-due-juice', now()->subMinutes(50));

        $cancelledOrder = $this->upsertUlid('orders', [
            'business_id' => $this->business->getKey(),
            'number' => 'DEMO-ORD-004',
        ], [
            'location_id' => $this->locations['main']->id,
            'venue_table_id' => null,
            'opened_by_user_id' => $this->users['waiter']->id,
            'cancelled_by_user_id' => $this->users['manager']->id,
            'idempotency_key' => 'demo-order-004',
            'request_snapshot' => json_encode(['demo' => true], JSON_THROW_ON_ERROR),
            'type' => 'takeaway',
            'status' => 'cancelled',
            'cancel_reason' => 'Demo customer cancellation',
            'currency' => $this->business->currency,
            'subtotal' => '4.8000',
            'discount_total' => '0.0000',
            'tax_total' => '0.8000',
            'grand_total' => '4.8000',
            'opened_at' => now()->subDays(2)->setTime(12, 0),
            'closed_at' => null,
            'cancelled_at' => now()->subDays(2)->setTime(12, 5),
        ]);

        $this->seedOrderItem($cancelledOrder->id, $this->products['juice']->id, 'Orange Juice', 'DEMO-JUICE', '1.0000', '4.8000', 'bar', 'voided', 'demo-item-cancelled', now()->subDays(2)->setTime(12, 1), true);
    }

    private function seedFinanceAndInvoices(): void
    {
        $expense = $this->upsertUlid('expenses', [
            'business_id' => $this->business->getKey(),
            'idempotency_key' => 'demo-expense-001',
        ], [
            'location_id' => $this->locations['main']->id,
            'created_by_user_id' => $this->users['finance']->id,
            'category' => 'Utilities',
            'description' => 'Demo electricity bill',
            'amount' => '92.5000',
            'currency' => $this->business->currency,
            'expense_date' => now()->toDateString(),
            'status' => 'posted',
            'request_snapshot' => json_encode(['demo' => true], JSON_THROW_ON_ERROR),
        ]);

        $this->upsertUlid('expenses', [
            'business_id' => $this->business->getKey(),
            'idempotency_key' => 'demo-expense-reversal',
        ], [
            'location_id' => $this->locations['main']->id,
            'created_by_user_id' => $this->users['finance']->id,
            'category' => 'Utilities',
            'description' => 'Demo electricity reversal',
            'amount' => '12.5000',
            'currency' => $this->business->currency,
            'expense_date' => now()->toDateString(),
            'status' => 'reversal',
            'reversal_of_expense_id' => $expense->id,
            'reversed_by_user_id' => $this->users['finance']->id,
            'reversal_reason' => 'Demo billing correction',
            'reversed_at' => now()->subHours(2),
            'request_snapshot' => json_encode(['demo' => true], JSON_THROW_ON_ERROR),
        ]);

        $order = DB::table('orders')
            ->where('business_id', $this->business->getKey())
            ->where('number', 'DEMO-ORD-001')
            ->first();

        if (! $order) {
            return;
        }

        $invoice = $this->upsertUlid('invoices', [
            'business_id' => $this->business->getKey(),
            'number' => 'DEMO-INV-001',
        ], [
            'location_id' => $this->locations['main']->id,
            'order_id' => $order->id,
            'created_by_user_id' => $this->users['finance']->id,
            'order_number_snapshot' => $order->number,
            'business_name_snapshot' => $this->business->name,
            'business_legal_name_snapshot' => $this->business->legal_name,
            'business_tax_number_snapshot' => $this->business->tax_number,
            'location_name_snapshot' => $this->locations['main']->name,
            'location_address_snapshot' => $this->locations['main']->address,
            'status' => 'issued',
            'fiscalization_status' => 'fiscalized',
            'fiscal_invoice_type' => 'CASH',
            'fiscal_invoice_number' => 'DM-2026-000001',
            'fiscal_ordinal_number' => 1,
            'fiscal_operator_code_snapshot' => 'dm107op007',
            'fiscal_business_unit_code_snapshot' => 'dm001bu001',
            'fiscal_tcr_code_snapshot' => 'dm011tc001',
            'nslf' => 'DEMO-NSLF-2026-000001-LOCAL-ONLY',
            'nivf' => 'DEMO-NIVF-2026-000001-LOCAL-ONLY',
            'verification_url' => null,
            'qr_payload' => 'DEMO|LOCAL_ONLY|INVOICE=DM-2026-000001|NIPT=L12345678A|NSLF=DEMO-NSLF-2026-000001-LOCAL-ONLY|NIVF=DEMO-NIVF-2026-000001-LOCAL-ONLY|TOTAL=20.00|CURRENCY=EUR',
            'fiscalized_at' => now()->subDay()->setTime(19, 6),
            'fiscalization_attempts' => 1,
            'fiscalization_error' => null,
            'currency' => $this->business->currency,
            'subtotal' => '16.6667',
            'discount_total' => '1.9000',
            'tax_total' => '3.3333',
            'grand_total' => '20.0000',
            'customer_name' => 'Demo Guest GmbH',
            'customer_tax_number' => 'DEMO-CUST-100',
            'issued_at' => now()->subDay()->setTime(19, 5),
        ]);

        $lines = [
            [1, 'House Burger', 'DEMO-BURGER', '1.0000', '13.5000', '8.6756', '20.0000', '10.2740', '2.0548', '12.3288'],
            [2, 'Cappuccino', 'DEMO-CAP', '2.0000', '4.2000', '8.6762', '20.0000', '6.3927', '1.2785', '7.6712'],
        ];

        foreach ($lines as [$position, $name, $sku, $qty, $unitPrice, $discountPercent, $taxRate, $subtotal, $tax, $total]) {
            $this->upsertUlid('invoice_lines', [
                'invoice_id' => $invoice->id,
                'position' => $position,
            ], [
                'business_id' => $this->business->getKey(),
                'product_name_snapshot' => $name,
                'sku_snapshot' => $sku,
                'unit_code_snapshot' => 'C62',
                'unit_label_snapshot' => 'pcs',
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'discount_percent' => $discountPercent,
                'tax_rate' => $taxRate,
                'line_subtotal' => $subtotal,
                'line_tax' => $tax,
                'line_total' => $total,
            ]);
        }

        $payment = DB::table('payments')
            ->where('business_id', $this->business->getKey())
            ->where('idempotency_key', 'demo-payment-001')
            ->first();

        if ($payment) {
            $this->upsertUlid('invoice_payment_snapshots', [
                'invoice_id' => $invoice->id,
                'position' => 1,
            ], [
                'business_id' => $this->business->getKey(),
                'method' => 'cash',
                'method_label' => 'Cash',
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'amount_base' => $payment->amount_base,
                'base_currency' => $payment->base_currency,
                'exchange_rate' => $payment->exchange_rate,
                'external_reference' => 'DEMO-PAYMENT',
            ]);
        }
    }

    private function seedPurchasing(): void
    {
        $supplier = $this->upsertUlid('suppliers', [
            'business_id' => $this->business->getKey(),
            'name' => 'Berlin Hospitality Supply',
        ], [
            'tax_number' => 'DEMO-SUP-001',
            'contact_name' => 'Sophie Supplier',
            'email' => 'supplier@example.test',
            'phone' => '+49 30 555 0101',
            'address' => 'Supply Street 10, Berlin',
            'is_active' => true,
        ]);

        $this->upsertUlid('suppliers', [
            'business_id' => $this->business->getKey(),
            'name' => 'Legacy Beverage Vendor',
        ], [
            'tax_number' => 'DEMO-SUP-LEGACY',
            'contact_name' => 'Legacy Contact',
            'email' => 'legacy-supplier@example.test',
            'is_active' => false,
        ]);

        $po = $this->upsertUlid('purchase_orders', [
            'business_id' => $this->business->getKey(),
            'number' => 'DEMO-PO-001',
        ], [
            'location_id' => $this->locations['main']->id,
            'supplier_id' => $supplier->id,
            'created_by_user_id' => $this->users['manager']->id,
            'placed_by_user_id' => $this->users['manager']->id,
            'supplier_name_snapshot' => $supplier->name,
            'supplier_tax_number_snapshot' => $supplier->tax_number,
            'idempotency_key' => 'demo-po-001',
            'request_snapshot' => json_encode(['demo' => true], JSON_THROW_ON_ERROR),
            'status' => 'partially_received',
            'currency' => $this->business->currency,
            'total_cost' => '58.0000',
            'notes' => 'Demo weekly replenishment',
            'ordered_at' => now()->subDays(3),
        ]);

        $waterLine = $this->upsertUlid('purchase_order_items', [
            'purchase_order_id' => $po->id,
            'product_id' => $this->products['water']->id,
        ], [
            'business_id' => $this->business->getKey(),
            'product_name_snapshot' => 'Sparkling Water',
            'sku_snapshot' => 'DEMO-WATER',
            'quantity_ordered' => '20.0000',
            'quantity_received' => '10.0000',
            'unit_cost' => '1.2000',
            'line_total' => '24.0000',
        ]);

        $juiceLine = $this->upsertUlid('purchase_order_items', [
            'purchase_order_id' => $po->id,
            'product_id' => $this->products['juice']->id,
        ], [
            'business_id' => $this->business->getKey(),
            'product_name_snapshot' => 'Orange Juice',
            'sku_snapshot' => 'DEMO-JUICE',
            'quantity_ordered' => '20.0000',
            'quantity_received' => '10.0000',
            'unit_cost' => '1.7000',
            'line_total' => '34.0000',
        ]);

        $receipt = $this->upsertUlid('goods_receipts', [
            'business_id' => $this->business->getKey(),
            'number' => 'DEMO-GRN-001',
        ], [
            'location_id' => $this->locations['main']->id,
            'purchase_order_id' => $po->id,
            'received_by_user_id' => $this->users['inventory']->id,
            'idempotency_key' => 'demo-grn-001',
            'request_snapshot' => json_encode(['demo' => true], JSON_THROW_ON_ERROR),
            'note' => 'Demo partial delivery',
            'received_at' => now()->subDays(2),
        ]);

        foreach ([
            [$waterLine, '10.0000', '1.2000', '12.0000'],
            [$juiceLine, '10.0000', '1.7000', '17.0000'],
        ] as [$line, $qty, $cost, $total]) {
            $this->upsertUlid('goods_receipt_items', [
                'goods_receipt_id' => $receipt->id,
                'purchase_order_item_id' => $line->id,
            ], [
                'business_id' => $this->business->getKey(),
                'product_id' => $line->product_id,
                'quantity_received' => $qty,
                'unit_cost_snapshot' => $cost,
                'line_total' => $total,
            ]);
        }

        $this->upsertUlid('purchase_order_events', [
            'purchase_order_id' => $po->id,
            'event' => 'created',
        ], [
            'business_id' => $this->business->getKey(),
            'actor_user_id' => $this->users['manager']->id,
            'previous_status' => null,
            'new_status' => 'draft',
            'metadata' => json_encode(['demo' => true], JSON_THROW_ON_ERROR),
            'occurred_at' => now()->subDays(4),
        ]);

        $this->upsertUlid('purchase_order_events', [
            'purchase_order_id' => $po->id,
            'event' => 'goods_received',
        ], [
            'business_id' => $this->business->getKey(),
            'actor_user_id' => $this->users['inventory']->id,
            'previous_status' => 'ordered',
            'new_status' => 'partially_received',
            'metadata' => json_encode(['goods_receipt_number' => $receipt->number, 'demo' => true], JSON_THROW_ON_ERROR),
            'occurred_at' => now()->subDays(2),
        ]);

        foreach ([
            ['water', $waterLine, '10.0000'],
            ['juice', $juiceLine, '10.0000'],
        ] as [$productKey, $line, $qty]) {
            $this->upsertUlid('inventory_movements', [
                'business_id' => $this->business->getKey(),
                'reference_type' => 'goods_receipt',
                'reference_id' => $receipt->id.'-'.$line->id,
            ], [
                'location_id' => $this->locations['main']->id,
                'product_id' => $this->products[$productKey]->id,
                'created_by_user_id' => $this->users['inventory']->id,
                'type' => 'purchase_receipt',
                'quantity_delta' => $qty,
                'idempotency_key' => 'demo-grn-movement-'.$productKey,
                'request_snapshot' => json_encode(['demo' => true], JSON_THROW_ON_ERROR),
                'note' => 'Demo received against '.$po->number,
                'occurred_at' => $receipt->received_at,
            ]);
        }

        $draftPo = $this->upsertUlid('purchase_orders', [
            'business_id' => $this->business->getKey(),
            'number' => 'DEMO-PO-002',
        ], [
            'location_id' => $this->locations['main']->id,
            'supplier_id' => $supplier->id,
            'created_by_user_id' => $this->users['manager']->id,
            'supplier_name_snapshot' => $supplier->name,
            'supplier_tax_number_snapshot' => $supplier->tax_number,
            'idempotency_key' => 'demo-po-002',
            'request_snapshot' => json_encode(['demo' => true], JSON_THROW_ON_ERROR),
            'status' => 'draft',
            'currency' => $this->business->currency,
            'total_cost' => '21.0000',
            'notes' => 'Draft PO to test Edit / Place / Cancel',
        ]);

        $this->upsertUlid('purchase_order_items', [
            'purchase_order_id' => $draftPo->id,
            'product_id' => $this->products['croissant']->id,
        ], [
            'business_id' => $this->business->getKey(),
            'product_name_snapshot' => 'Butter Croissant',
            'sku_snapshot' => 'DEMO-CROIS',
            'quantity_ordered' => '20.0000',
            'quantity_received' => '0.0000',
            'unit_cost' => '1.0500',
            'line_total' => '21.0000',
        ]);
    }

    private function seedInventoryControl(): void
    {
        $transfer = $this->upsertUlid('inventory_transfers', [
            'business_id' => $this->business->getKey(),
            'number' => 'DEMO-TR-001',
        ], [
            'source_location_id' => $this->locations['main']->id,
            'destination_location_id' => $this->locations['rooftop']->id,
            'created_by_user_id' => $this->users['inventory']->id,
            'idempotency_key' => 'demo-transfer-001',
            'request_snapshot' => json_encode(['demo' => true], JSON_THROW_ON_ERROR),
            'status' => 'posted',
            'note' => 'Demo transfer to rooftop',
            'posted_at' => now()->subDay(),
        ]);

        $this->upsertUlid('inventory_transfer_items', [
            'inventory_transfer_id' => $transfer->id,
            'product_id' => $this->products['water']->id,
        ], [
            'business_id' => $this->business->getKey(),
            'product_name_snapshot' => 'Sparkling Water',
            'sku_snapshot' => 'DEMO-WATER',
            'quantity' => '4.0000',
        ]);

        $count = $this->upsertUlid('inventory_counts', [
            'business_id' => $this->business->getKey(),
            'number' => 'DEMO-COUNT-001',
        ], [
            'location_id' => $this->locations['main']->id,
            'created_by_user_id' => $this->users['inventory']->id,
            'idempotency_key' => 'demo-count-001',
            'request_snapshot' => json_encode(['demo' => true], JSON_THROW_ON_ERROR),
            'status' => 'draft',
            'note' => 'Demo cycle count in progress',
            'started_at' => now()->subHours(2),
        ]);

        foreach (['water', 'juice', 'croissant'] as $productKey) {
            $stock = DB::table('inventory_stocks')
                ->where('business_id', $this->business->getKey())
                ->where('location_id', $this->locations['main']->id)
                ->where('product_id', $this->products[$productKey]->id)
                ->first();

            $this->upsertUlid('inventory_count_items', [
                'inventory_count_id' => $count->id,
                'product_id' => $this->products[$productKey]->id,
            ], [
                'business_id' => $this->business->getKey(),
                'product_name_snapshot' => $this->products[$productKey]->name,
                'sku_snapshot' => $this->products[$productKey]->sku,
                'expected_quantity' => (string) ($stock->quantity_on_hand ?? '0.0000'),
                'counted_quantity' => $productKey === 'water' ? '27.0000' : null,
                'variance_quantity' => $productKey === 'water'
                    ? (string) (27 - (float) ($stock->quantity_on_hand ?? 0))
                    : null,
            ]);
        }

        $this->upsertUlid('inventory_count_events', [
            'inventory_count_id' => $count->id,
            'event' => 'created',
        ], [
            'business_id' => $this->business->getKey(),
            'actor_user_id' => $this->users['inventory']->id,
            'previous_status' => null,
            'new_status' => 'draft',
            'metadata' => json_encode(['demo' => true], JSON_THROW_ON_ERROR),
            'occurred_at' => $count->started_at,
        ]);
    }

    private function seedInvitationsAndAudits(): void
    {
        $managerRole = $this->roles['manager'];
        $token = 'demo-invitation-token-not-for-login';

        $invitation = $this->upsertUlid('staff_invitations', [
            'business_id' => $this->business->getKey(),
            'email' => 'demo.candidate@hospitality.local',
        ], [
            'status' => 'pending',
            'role_id' => $managerRole->id,
            'invited_by_user_id' => $this->owner->id,
            'role_name_snapshot' => $managerRole->name,
            'role_permissions_snapshot' => json_encode(
                DB::table('permission_role as pr')
                    ->join('permissions as p', 'p.id', '=', 'pr.permission_id')
                    ->where('pr.role_id', $managerRole->id)
                    ->orderBy('p.key')
                    ->pluck('p.key')
                    ->all(),
                JSON_THROW_ON_ERROR,
            ),
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(7),
            'expired_at' => null,
            'accepted_at' => null,
            'accepted_by_user_id' => null,
            'revoked_at' => null,
            'revoked_by_user_id' => null,
            'last_reissued_at' => null,
            'last_reissued_by_user_id' => null,
            'reissue_count' => 0,
        ]);

        $this->upsertUlid('staff_invitation_events', [
            'staff_invitation_id' => $invitation->id,
            'event' => 'created',
        ], [
            'business_id' => $this->business->getKey(),
            'actor_user_id' => $this->owner->id,
            'previous_status' => null,
            'new_status' => 'pending',
            'metadata' => json_encode(['demo' => true, 'role_name' => $managerRole->name], JSON_THROW_ON_ERROR),
            'occurred_at' => now()->subHour(),
        ]);

        $waiter = $this->users['waiter'];
        $waiterRole = $this->roles['waiter'];

        $this->upsertUlid('business_membership_audits', [
            'business_id' => $this->business->getKey(),
            'target_user_id' => $waiter->id,
            'action' => 'status_changed',
        ], [
            'performed_by_user_id' => $this->owner->id,
            'previous_role_id' => $waiterRole->id,
            'previous_role_name' => $waiterRole->name,
            'previous_role_slug' => $waiterRole->slug,
            'previous_status' => 'inactive',
            'new_role_id' => $waiterRole->id,
            'new_role_name' => $waiterRole->name,
            'new_role_slug' => $waiterRole->slug,
            'new_status' => 'active',
            'performed_at' => now()->subDays(3),
        ]);
    }

    private function seedSettings(): void
    {
        foreach ([
            'service_mode' => ['value' => 'table_and_counter'],
            'receipt_footer' => ['value' => 'Thank you from Hospitality Demo Lab'],
            'demo_workspace' => ['value' => true],
        ] as $key => $value) {
            $this->upsertUlid('business_settings', [
                'business_id' => $this->business->getKey(),
                'key' => $key,
            ], [
                'value' => json_encode($value, JSON_THROW_ON_ERROR),
            ]);
        }

        $this->upsertUlid('fiscalization_profiles', [
            'business_id' => $this->business->getKey(),
        ], [
            'provider' => 'direct_dpt',
            'environment' => 'test',
            'status' => 'configured',
            'software_code' => 'dm999sw001',
            'certificate_secret_ref' => 'secret:demo-fiscal-certificate.p12',
            'certificate_password_secret_ref' => 'env:DEMO_FISCAL_CERT_PASSWORD',
            'endpoint' => 'https://example.test/demo-dpt',
            'is_issuer_in_vat' => true,
            'last_verified_at' => null,
            'last_test_verified_at' => null,
            'preflight_checked_at' => null,
            'preflight_status' => null,
        ]);
    }

    private function seedOrderItem(
        string $orderId,
        string $productId,
        string $name,
        string $sku,
        string $qty,
        string $unitPrice,
        string $station,
        string $status,
        string $idempotencyKey,
        mixed $sentAt,
        bool $voided = false,
    ): void {
        $quantity = (float) $qty;
        $price = (float) $unitPrice;
        $subtotal = $quantity * $price;
        $tax = $subtotal - ($subtotal / 1.2);

        $this->upsertUlid('order_items', [
            'business_id' => $this->business->getKey(),
            'idempotency_key' => $idempotencyKey,
        ], [
            'order_id' => $orderId,
            'product_id' => $productId,
            'voided_by_user_id' => $voided ? $this->users['manager']->id : null,
            'product_name_snapshot' => $name,
            'sku_snapshot' => $sku,
            'quantity' => $qty,
            'unit_price' => $unitPrice,
            'original_unit_price' => null,
            'tax_rate' => '20.0000',
            'line_subtotal' => number_format($subtotal, 4, '.', ''),
            'line_tax' => number_format($tax, 4, '.', ''),
            'line_total' => number_format($subtotal, 4, '.', ''),
            'preparation_station' => $station,
            'preparation_status' => $status,
            'note' => $status === 'sent' ? 'Demo note: no ice / warm pastry' : null,
            'request_snapshot' => json_encode(['demo' => true], JSON_THROW_ON_ERROR),
            'sent_at' => $sentAt,
            'preparing_at' => $status === 'preparing' ? $sentAt->copy()->addMinutes(2) : null,
            'prepared_at' => in_array($status, ['ready', 'served'], true) ? $sentAt->copy()->addMinutes(5) : null,
            'served_at' => $status === 'served' ? $sentAt->copy()->addMinutes(7) : null,
            'void_reason' => $voided ? 'Demo cancelled line' : null,
            'voided_at' => $voided ? $sentAt->copy()->addMinutes(4) : null,
        ]);
    }

    private function upsertUlid(string $table, array $where, array $values): object
    {
        $existing = DB::table($table)->where($where)->first();
        $now = now();

        if ($existing) {
            $updates = $this->filtered($table, [
                ...$values,
                'updated_at' => $now,
            ]);

            if ($updates !== []) {
                DB::table($table)->where('id', $existing->id)->update($updates);
            }

            return DB::table($table)->where('id', $existing->id)->first();
        }

        $id = (string) Str::ulid();
        $payload = $this->filtered($table, [
            'id' => $id,
            ...$where,
            ...$values,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table($table)->insert($payload);

        return DB::table($table)->where('id', $id)->first();
    }

    private function filtered(string $table, array $payload): array
    {
        $columns = array_flip(Schema::getColumnListing($table));

        return array_filter(
            $payload,
            static fn (mixed $value, string $key): bool => isset($columns[$key]),
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
