<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('idempotency_key', 64)->nullable()->after('number');
            $table->json('request_snapshot')->nullable()->after('idempotency_key');
            $table->unique(['business_id', 'idempotency_key'], 'order_business_idempotency_uq');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->string('idempotency_key', 64)->nullable()->after('note');
            $table->json('request_snapshot')->nullable()->after('idempotency_key');
            $table->unique(['business_id', 'idempotency_key'], 'order_item_business_idempotency_uq');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropUnique('order_item_business_idempotency_uq');
            $table->dropColumn(['idempotency_key', 'request_snapshot']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique('order_business_idempotency_uq');
            $table->dropColumn(['idempotency_key', 'request_snapshot']);
        });
    }
};
