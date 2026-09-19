<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('tax_number', 80)->nullable();
            $table->string('contact_name', 120)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('phone', 80)->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['business_id', 'is_active', 'name'], 'supplier_business_active_name_idx');
        });

        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('location_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('placed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('number', 48);
            $table->string('status', 32)->default('draft');
            $table->char('currency', 3);
            $table->decimal('total_cost', 18, 4)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('ordered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'number'], 'purchase_order_business_number_uq');
            $table->index(['business_id', 'location_id', 'status', 'created_at'], 'purchase_order_lookup_idx');
            $table->index(['business_id', 'supplier_id', 'status'], 'purchase_order_supplier_status_idx');
        });

        Schema::create('purchase_order_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('product_id')->constrained()->restrictOnDelete();
            $table->string('product_name_snapshot');
            $table->string('sku_snapshot', 64)->nullable();
            $table->decimal('quantity_ordered', 18, 4);
            $table->decimal('quantity_received', 18, 4)->default(0);
            $table->decimal('unit_cost', 18, 4);
            $table->decimal('line_total', 18, 4);
            $table->timestamps();

            $table->unique(['purchase_order_id', 'product_id'], 'purchase_order_product_uq');
            $table->index(['business_id', 'product_id'], 'purchase_item_product_idx');
        });

        Schema::create('goods_receipts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('location_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('received_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('number', 48);
            $table->text('note')->nullable();
            $table->timestamp('received_at');
            $table->timestamps();

            $table->unique(['business_id', 'number'], 'goods_receipt_business_number_uq');
            $table->index(['business_id', 'purchase_order_id', 'received_at'], 'goods_receipt_po_time_idx');
        });

        Schema::create('goods_receipt_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('goods_receipt_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('purchase_order_item_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity_received', 18, 4);
            $table->decimal('unit_cost_snapshot', 18, 4);
            $table->decimal('line_total', 18, 4);
            $table->timestamps();

            $table->unique(['goods_receipt_id', 'purchase_order_item_id'], 'goods_receipt_item_uq');
        });

        Schema::create('purchase_order_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('event', 40);
            $table->string('previous_status', 32)->nullable();
            $table->string('new_status', 32);
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['business_id', 'purchase_order_id', 'occurred_at'], 'purchase_event_po_time_idx');
        });

        Schema::create('business_purchase_counters', function (Blueprint $table): void {
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->date('business_date');
            $table->string('document_type', 16);
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();
            $table->primary(['business_id', 'business_date', 'document_type'], 'purchase_counter_pk');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_purchase_counters');
        Schema::dropIfExists('purchase_order_events');
        Schema::dropIfExists('goods_receipt_items');
        Schema::dropIfExists('goods_receipts');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('suppliers');
    }
};
