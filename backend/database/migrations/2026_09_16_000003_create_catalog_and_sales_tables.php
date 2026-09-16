<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('color', 16)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['business_id', 'is_active', 'sort_order']);
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('product_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('sku', 64)->nullable();
            $table->string('barcode', 64)->nullable();
            $table->decimal('sale_price', 18, 4);
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->string('preparation_station', 32)->nullable();
            $table->boolean('tracks_stock')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['business_id', 'sku']);
            $table->index(['business_id', 'is_active', 'name']);
        });

        Schema::create('venue_areas', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('venue_tables', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('venue_area_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 64);
            $table->unsignedSmallInteger('capacity')->default(2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['location_id', 'name']);
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('location_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('venue_table_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('opened_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('number', 40);
            $table->string('type', 24)->default('table');
            $table->string('status', 24)->default('open');
            $table->char('currency', 3)->default('ALL');
            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('discount_total', 18, 4)->default(0);
            $table->decimal('tax_total', 18, 4)->default(0);
            $table->decimal('grand_total', 18, 4)->default(0);
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'number']);
            $table->index(['business_id', 'location_id', 'status', 'opened_at']);
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name_snapshot');
            $table->string('sku_snapshot', 64)->nullable();
            $table->decimal('quantity', 14, 4);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->decimal('line_subtotal', 18, 4);
            $table->decimal('line_tax', 18, 4)->default(0);
            $table->decimal('line_total', 18, 4);
            $table->string('preparation_station', 32)->nullable();
            $table->string('preparation_status', 24)->default('pending');
            $table->text('note')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('prepared_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'preparation_station', 'preparation_status']);
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('collected_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('method', 24);
            $table->string('status', 24)->default('completed');
            $table->decimal('amount', 18, 4);
            $table->char('currency', 3);
            $table->string('idempotency_key', 100);
            $table->string('external_reference')->nullable();
            $table->timestamp('paid_at');
            $table->timestamps();
            $table->unique(['business_id', 'idempotency_key']);
            $table->index(['business_id', 'order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('venue_tables');
        Schema::dropIfExists('venue_areas');
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_categories');
    }
};
