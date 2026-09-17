<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inventory_stocks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity_on_hand', 18, 4)->default(0);
            $table->decimal('reorder_level', 18, 4)->default(0);
            $table->timestamps();
            $table->unique(['business_id','location_id','product_id']);
        });

        Schema::create('inventory_movements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('location_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('type', 24);
            $table->decimal('quantity_delta', 18, 4);
            $table->string('reference_type', 40)->nullable();
            $table->string('reference_id', 64)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['business_id','location_id','product_id','occurred_at'], 'inventory_movement_lookup_idx');
        });

        Schema::create('expenses', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('category', 80);
            $table->string('description');
            $table->decimal('amount', 18, 4);
            $table->char('currency', 3);
            $table->date('expense_date');
            $table->string('status', 24)->default('posted');
            $table->timestamps();
            $table->index(['business_id','expense_date','status']);
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('location_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('number', 48);
            $table->string('status', 24)->default('draft');
            $table->char('currency', 3);
            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('tax_total', 18, 4)->default(0);
            $table->decimal('grand_total', 18, 4)->default(0);
            $table->string('customer_name')->nullable();
            $table->string('customer_tax_number', 80)->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id','number']);
            $table->index(['business_id','location_id','status','created_at']);
        });

        Schema::create('business_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->string('key', 120);
            $table->json('value')->nullable();
            $table->timestamps();
            $table->unique(['business_id','key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_settings');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_stocks');
    }
};
