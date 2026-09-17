<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('discount_applied_by_user_id')->nullable()->after('cancelled_by_user_id')->constrained('users')->nullOnDelete();
            $table->string('discount_reason', 500)->nullable()->after('discount_total');
            $table->timestamp('discount_applied_at')->nullable()->after('discount_reason');
            $table->foreignUlid('previous_venue_table_id')->nullable()->after('venue_table_id')->constrained('venue_tables')->nullOnDelete();
            $table->foreignId('table_moved_by_user_id')->nullable()->after('discount_applied_by_user_id')->constrained('users')->nullOnDelete();
            $table->string('table_move_reason', 500)->nullable()->after('cancel_reason');
            $table->timestamp('table_moved_at')->nullable()->after('cancelled_at');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->foreignId('price_overridden_by_user_id')->nullable()->after('voided_by_user_id')->constrained('users')->nullOnDelete();
            $table->decimal('original_unit_price', 18, 4)->nullable()->after('unit_price');
            $table->string('price_override_reason', 500)->nullable()->after('void_reason');
            $table->timestamp('price_overridden_at')->nullable()->after('voided_at');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropForeign(['price_overridden_by_user_id']);
            $table->dropColumn(['price_overridden_by_user_id', 'original_unit_price', 'price_override_reason', 'price_overridden_at']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign(['previous_venue_table_id']);
            $table->dropForeign(['discount_applied_by_user_id']);
            $table->dropForeign(['table_moved_by_user_id']);
            $table->dropColumn([
                'previous_venue_table_id', 'discount_applied_by_user_id', 'discount_reason', 'discount_applied_at',
                'table_moved_by_user_id', 'table_move_reason', 'table_moved_at',
            ]);
        });
    }
};
