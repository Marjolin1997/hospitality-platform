<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inventory_transfers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('source_location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignUlid('destination_location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('number', 48);
            $table->string('status', 24)->default('posted');
            $table->text('note');
            $table->timestamp('posted_at');
            $table->timestamps();

            $table->unique(['business_id', 'number'], 'inventory_transfer_number_uq');
            $table->index(['business_id', 'source_location_id', 'posted_at'], 'inventory_transfer_source_idx');
            $table->index(['business_id', 'destination_location_id', 'posted_at'], 'inventory_transfer_destination_idx');
        });

        Schema::create('inventory_transfer_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('inventory_transfer_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('product_id')->constrained()->restrictOnDelete();
            $table->string('product_name_snapshot');
            $table->string('sku_snapshot', 64)->nullable();
            $table->decimal('quantity', 18, 4);
            $table->timestamps();

            $table->unique(['inventory_transfer_id', 'product_id'], 'inventory_transfer_product_uq');
        });

        Schema::create('inventory_counts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('number', 48);
            $table->string('status', 24)->default('draft');
            $table->text('note')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'number'], 'inventory_count_number_uq');
            $table->index(['business_id', 'location_id', 'status', 'started_at'], 'inventory_count_lookup_idx');
        });

        Schema::create('inventory_count_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('inventory_count_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('product_id')->constrained()->restrictOnDelete();
            $table->string('product_name_snapshot');
            $table->string('sku_snapshot', 64)->nullable();
            $table->decimal('expected_quantity', 18, 4);
            $table->decimal('counted_quantity', 18, 4)->nullable();
            $table->decimal('variance_quantity', 18, 4)->nullable();
            $table->timestamps();

            $table->unique(['inventory_count_id', 'product_id'], 'inventory_count_product_uq');
        });

        Schema::create('inventory_count_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('inventory_count_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('event', 32);
            $table->string('previous_status', 24)->nullable();
            $table->string('new_status', 24);
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['business_id', 'inventory_count_id', 'occurred_at'], 'inventory_count_event_idx');
        });

        Schema::create('business_inventory_counters', function (Blueprint $table): void {
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->date('business_date');
            $table->string('document_type', 20);
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();

            $table->primary(['business_id', 'business_date', 'document_type'], 'inventory_counter_pk');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_inventory_counters');
        Schema::dropIfExists('inventory_count_events');
        Schema::dropIfExists('inventory_count_items');
        Schema::dropIfExists('inventory_counts');
        Schema::dropIfExists('inventory_transfer_items');
        Schema::dropIfExists('inventory_transfers');
    }
};
