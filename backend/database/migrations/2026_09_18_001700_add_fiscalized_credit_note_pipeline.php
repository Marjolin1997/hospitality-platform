<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('invoice_credit_notes', function (Blueprint $table): void {
            $table->string('fiscal_invoice_type', 24)->nullable()->after('fiscalization_status');
            $table->string('fiscal_operator_code_snapshot', 64)->nullable()->after('original_invoice_nslf_snapshot');
            $table->string('fiscal_business_unit_code_snapshot', 64)->nullable()->after('fiscal_operator_code_snapshot');
            $table->string('fiscal_tcr_code_snapshot', 64)->nullable()->after('fiscal_business_unit_code_snapshot');
            $table->timestamp('original_invoice_issued_at_snapshot')->nullable()->after('fiscal_tcr_code_snapshot');
        });

        Schema::table('invoice_credit_note_lines', function (Blueprint $table): void {
            $table->string('unit_code_snapshot', 16)->default('C62')->after('sku_snapshot');
            $table->string('unit_label_snapshot', 64)->default('Copë')->after('unit_code_snapshot');
            $table->decimal('discount_percent', 9, 4)->default(0)->after('unit_price');
        });

        Schema::create('credit_note_fiscalization_attempts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('invoice_credit_note_id')->constrained('invoice_credit_notes')->restrictOnDelete();
            $table->unsignedSmallInteger('attempt_no');
            $table->string('provider', 32);
            $table->string('environment', 16);
            $table->string('status', 24);
            $table->boolean('retryable')->default(false);
            $table->timestamp('next_retry_at')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('request_id', 128)->nullable();
            $table->char('payload_hash', 64)->nullable();
            $table->string('nslf', 128)->nullable();
            $table->string('nivf', 128)->nullable();
            $table->string('error_code', 128)->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['invoice_credit_note_id','attempt_no'], 'credit_fiscal_attempt_no_uq');
            $table->index(['business_id','status','started_at'], 'credit_fiscal_attempt_status_idx');
            $table->index(['status','retryable','next_retry_at'], 'credit_fiscal_retry_queue_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_note_fiscalization_attempts');

        Schema::table('invoice_credit_note_lines', function (Blueprint $table): void {
            $table->dropColumn(['unit_code_snapshot','unit_label_snapshot','discount_percent']);
        });

        Schema::table('invoice_credit_notes', function (Blueprint $table): void {
            $table->dropColumn([
                'fiscal_invoice_type','fiscal_operator_code_snapshot','fiscal_business_unit_code_snapshot',
                'fiscal_tcr_code_snapshot','original_invoice_issued_at_snapshot',
            ]);
        });
    }
};
