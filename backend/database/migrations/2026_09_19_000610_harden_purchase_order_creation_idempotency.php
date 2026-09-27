<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $needsIdempotencyKey = ! Schema::hasColumn('purchase_orders', 'idempotency_key');
        $needsRequestSnapshot = ! Schema::hasColumn('purchase_orders', 'request_snapshot');

        if (! $needsIdempotencyKey && ! $needsRequestSnapshot) {
            return;
        }

        Schema::table('purchase_orders', function (Blueprint $table) use ($needsIdempotencyKey, $needsRequestSnapshot): void {
            if ($needsIdempotencyKey) {
                $table->string('idempotency_key', 64)->nullable()->after('number');
            }

            if ($needsRequestSnapshot) {
                $table->json('request_snapshot')->nullable()->after('idempotency_key');
            }
        });

        if ($needsIdempotencyKey) {
            Schema::table('purchase_orders', function (Blueprint $table): void {
                $table->unique(['business_id', 'idempotency_key'], 'purchase_order_business_idempotency_uq');
            });
        }
    }

    public function down(): void
    {
        // Compatibility bridge only. The canonical 000300 purchasing migration now
        // owns these columns and indexes, so rolling this bridge back must not
        // remove schema that a fresh installation legitimately requires.
    }
};
