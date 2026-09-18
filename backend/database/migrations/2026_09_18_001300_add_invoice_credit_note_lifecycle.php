<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('business_credit_note_counters')) {
            Schema::create('business_credit_note_counters', function (Blueprint $table): void {
                $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
                $table->date('business_date');
                $table->unsignedBigInteger('last_number')->default(0);
                $table->timestamps();
                $table->primary(['business_id', 'business_date']);
            });
        }

        if (! Schema::hasTable('invoice_credit_notes')) {
            Schema::create('invoice_credit_notes', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('business_id')->constrained()->restrictOnDelete();
                $table->foreignUlid('invoice_id')->constrained('invoices')->restrictOnDelete();
                $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
                $table->string('number', 56);
                $table->string('invoice_number_snapshot', 48);
                $table->string('status', 24)->default('issued');
                $table->char('currency', 3);
                $table->decimal('subtotal', 18, 4);
                $table->decimal('discount_total', 18, 4)->default(0);
                $table->decimal('tax_total', 18, 4);
                $table->decimal('grand_total', 18, 4);
                $table->string('customer_name_snapshot')->nullable();
                $table->string('customer_tax_number_snapshot', 80)->nullable();
                $table->string('reason', 500);
                $table->string('idempotency_key', 100);
                $table->timestamp('issued_at');
                $table->timestamps();
                $table->unique(['business_id', 'number'], 'credit_notes_business_number_uq');
                $table->unique(['business_id', 'invoice_id'], 'credit_notes_business_invoice_uq');
                $table->unique(['business_id', 'idempotency_key'], 'credit_notes_business_idem_uq');
                $table->index(['business_id', 'status', 'issued_at'], 'credit_notes_business_status_issued_idx');
            });
        }

        if (! Schema::hasTable('invoice_credit_note_lines')) {
            Schema::create('invoice_credit_note_lines', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('business_id')->constrained()->restrictOnDelete();
                $table->foreignUlid('invoice_credit_note_id')->constrained('invoice_credit_notes')->restrictOnDelete();
                $table->foreignUlid('invoice_line_id')->nullable()->constrained('invoice_lines')->nullOnDelete();
                $table->unsignedInteger('position');
                $table->string('product_name_snapshot');
                $table->string('sku_snapshot', 64)->nullable();
                $table->decimal('quantity', 18, 4);
                $table->decimal('unit_price', 18, 4);
                $table->decimal('tax_rate', 9, 4);
                $table->decimal('line_subtotal', 18, 4);
                $table->decimal('line_tax', 18, 4);
                $table->decimal('line_total', 18, 4);
                $table->timestamps();
                $table->unique(['invoice_credit_note_id', 'position'], 'credit_note_lines_position_uq');
                $table->index(['business_id', 'invoice_credit_note_id'], 'credit_note_lines_business_credit_idx');
            });
        } elseif (! Schema::hasIndex('invoice_credit_note_lines', 'credit_note_lines_business_credit_idx')) {
            Schema::table('invoice_credit_note_lines', function (Blueprint $table): void {
                $table->index(['business_id', 'invoice_credit_note_id'], 'credit_note_lines_business_credit_idx');
            });
        }

        if (! Schema::hasColumn('payment_refunds', 'invoice_credit_note_id')) {
            Schema::table('payment_refunds', function (Blueprint $table): void {
                $table->foreignUlid('invoice_credit_note_id')
                    ->nullable()
                    ->after('payment_id')
                    ->constrained('invoice_credit_notes')
                    ->restrictOnDelete();
                $table->index(['business_id', 'invoice_credit_note_id', 'status'], 'refunds_business_credit_status_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payment_refunds', 'invoice_credit_note_id')) {
            Schema::table('payment_refunds', function (Blueprint $table): void {
                $table->dropIndex('refunds_business_credit_status_idx');
                $table->dropForeign(['invoice_credit_note_id']);
                $table->dropColumn('invoice_credit_note_id');
            });
        }

        Schema::dropIfExists('invoice_credit_note_lines');
        Schema::dropIfExists('invoice_credit_notes');
        Schema::dropIfExists('business_credit_note_counters');
    }
};
