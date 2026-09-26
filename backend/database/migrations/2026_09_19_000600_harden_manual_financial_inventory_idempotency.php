<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->string('idempotency_key', 64)->nullable()->after('reference_id');
            $table->json('request_snapshot')->nullable()->after('idempotency_key');
            $table->unique(['business_id', 'idempotency_key'], 'inventory_movement_business_idempotency_uq');
        });

        Schema::table('cash_movements', function (Blueprint $table): void {
            $table->string('idempotency_key', 64)->nullable()->after('reference_id');
            $table->json('request_snapshot')->nullable()->after('idempotency_key');
            $table->unique(['business_id', 'idempotency_key'], 'cash_movement_business_idempotency_uq');
        });

        Schema::table('expenses', function (Blueprint $table): void {
            $table->string('idempotency_key', 64)->nullable()->after('status');
            $table->json('request_snapshot')->nullable()->after('idempotency_key');
            $table->unique(['business_id', 'idempotency_key'], 'expense_business_idempotency_uq');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->dropUnique('expense_business_idempotency_uq');
            $table->dropColumn(['idempotency_key', 'request_snapshot']);
        });

        Schema::table('cash_movements', function (Blueprint $table): void {
            $table->dropUnique('cash_movement_business_idempotency_uq');
            $table->dropColumn(['idempotency_key', 'request_snapshot']);
        });

        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->dropUnique('inventory_movement_business_idempotency_uq');
            $table->dropColumn(['idempotency_key', 'request_snapshot']);
        });
    }
};
