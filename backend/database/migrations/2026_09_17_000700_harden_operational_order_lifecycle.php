<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('cancelled_by_user_id')->nullable()->after('opened_by_user_id')->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 500)->nullable()->after('status');
            $table->timestamp('cancelled_at')->nullable()->after('closed_at');
            $table->index(['business_id', 'status', 'updated_at'], 'orders_business_status_updated_index');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->foreignId('voided_by_user_id')->nullable()->after('product_id')->constrained('users')->nullOnDelete();
            $table->string('void_reason', 500)->nullable()->after('note');
            $table->timestamp('preparing_at')->nullable()->after('sent_at');
            $table->timestamp('ready_at')->nullable()->after('preparing_at');
            $table->timestamp('served_at')->nullable()->after('ready_at');
            $table->timestamp('voided_at')->nullable()->after('served_at');
            $table->index(['business_id', 'preparation_status', 'updated_at'], 'order_items_business_prep_updated_index');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropIndex('order_items_business_prep_updated_index');
            $table->dropForeign(['voided_by_user_id']);
            $table->dropColumn(['voided_by_user_id', 'void_reason', 'preparing_at', 'ready_at', 'served_at', 'voided_at']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_business_status_updated_index');
            $table->dropForeign(['cancelled_by_user_id']);
            $table->dropColumn(['cancelled_by_user_id', 'cancel_reason', 'cancelled_at']);
        });
    }
};
