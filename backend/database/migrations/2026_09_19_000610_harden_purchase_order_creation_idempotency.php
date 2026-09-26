<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->string('idempotency_key', 64)->nullable()->after('number');
            $table->json('request_snapshot')->nullable()->after('idempotency_key');
            $table->unique(['business_id', 'idempotency_key'], 'purchase_order_business_idempotency_uq');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropUnique('purchase_order_business_idempotency_uq');
            $table->dropColumn(['idempotency_key', 'request_snapshot']);
        });
    }
};
