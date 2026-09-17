<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('order_transfer_audits', function (Blueprint $table): void {
            $table->string('idempotency_key', 100)->after('operation');
            $table->unique(['business_id', 'idempotency_key'], 'order_transfer_business_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('order_transfer_audits', function (Blueprint $table): void {
            $table->dropUnique('order_transfer_business_idempotency_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
