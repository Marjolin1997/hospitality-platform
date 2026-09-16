<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cash_registers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 32);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['business_id', 'code']);
        });

        Schema::create('cash_sessions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('location_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('cash_register_id')->constrained()->restrictOnDelete();
            $table->foreignId('opened_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->char('base_currency', 3);
            $table->decimal('opening_cash', 18, 4)->default(0);
            $table->decimal('expected_cash', 18, 4)->nullable();
            $table->decimal('counted_cash', 18, 4)->nullable();
            $table->decimal('cash_difference', 18, 4)->nullable();
            $table->string('status', 24)->default('open');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->text('closing_note')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'location_id', 'status']);
            $table->index(['cash_register_id', 'status']);
        });

        Schema::create('cash_movements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('cash_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('type', 24); // cash_in, cash_out, sale, refund
            $table->decimal('amount', 18, 4);
            $table->char('currency', 3);
            $table->decimal('amount_base', 18, 4);
            $table->decimal('exchange_rate', 24, 10)->default(1);
            $table->string('reason', 255)->nullable();
            $table->string('reference_type', 64)->nullable();
            $table->string('reference_id', 64)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['business_id', 'cash_session_id', 'occurred_at']);
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignUlid('cash_session_id')->nullable()->after('order_id')->constrained()->restrictOnDelete();
            $table->decimal('amount_base', 18, 4)->after('amount');
            $table->char('base_currency', 3)->after('currency');
            $table->decimal('exchange_rate', 24, 10)->default(1)->after('base_currency');
            $table->decimal('tendered_amount', 18, 4)->nullable()->after('exchange_rate');
            $table->decimal('change_amount', 18, 4)->nullable()->after('tendered_amount');
            $table->json('exchange_rate_snapshot')->nullable()->after('change_amount');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropForeign(['cash_session_id']);
            $table->dropColumn(['cash_session_id', 'amount_base', 'base_currency', 'exchange_rate', 'tendered_amount', 'change_amount', 'exchange_rate_snapshot']);
        });
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cash_sessions');
        Schema::dropIfExists('cash_registers');
    }
};
