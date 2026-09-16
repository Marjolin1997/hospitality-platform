<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_refunds', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('payment_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('cash_session_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('refunded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->decimal('amount', 18, 4);
            $table->decimal('amount_base', 18, 4);
            $table->char('currency', 3);
            $table->char('base_currency', 3);
            $table->decimal('exchange_rate', 24, 10);
            $table->string('reason', 500);
            $table->string('idempotency_key', 100);
            $table->string('status', 24)->default('completed');
            $table->timestamp('refunded_at');
            $table->timestamps();
            $table->unique(['business_id', 'idempotency_key']);
            $table->index(['business_id', 'payment_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_refunds');
    }
};
