<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_transfer_audits', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('location_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('source_order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignUlid('destination_order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('performed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('operation', 16);
            $table->string('reason', 500);
            $table->decimal('total_before', 18, 4);
            $table->decimal('source_total_after', 18, 4);
            $table->decimal('destination_total_after', 18, 4);
            $table->json('item_ids');
            $table->timestamp('performed_at');
            $table->timestamps();
            $table->index(['business_id', 'location_id', 'performed_at']);
            $table->index(['source_order_id', 'destination_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_transfer_audits');
    }
};
