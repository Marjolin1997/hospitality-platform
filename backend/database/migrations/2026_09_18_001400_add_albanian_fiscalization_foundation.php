<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fiscalization_profiles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32)->default('direct_dpt');
            $table->string('environment', 16)->default('test');
            $table->string('status', 24)->default('unconfigured');
            $table->string('software_code', 64)->nullable();
            $table->string('certificate_secret_ref')->nullable();
            $table->string('endpoint')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
            $table->unique('business_id');
        });

        Schema::table('locations', function (Blueprint $table): void {
            $table->string('fiscal_business_unit_code', 64)->nullable()->after('code');
        });

        Schema::table('cash_registers', function (Blueprint $table): void {
            $table->string('fiscal_tcr_code', 64)->nullable()->after('code');
        });

        Schema::table('business_user', function (Blueprint $table): void {
            $table->string('fiscal_operator_code', 64)->nullable()->after('role_id');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->string('unit_code', 16)->default('C62')->after('barcode');
            $table->string('unit_label', 64)->default('Copë')->after('unit_code');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('fiscalization_status', 24)->default('not_fiscalized')->after('status');
            $table->string('fiscal_invoice_type', 24)->nullable()->after('fiscalization_status');
            $table->string('fiscal_invoice_number', 64)->nullable()->after('fiscal_invoice_type');
            $table->unsignedBigInteger('fiscal_ordinal_number')->nullable()->after('fiscal_invoice_number');
            $table->string('fiscal_operator_code_snapshot', 64)->nullable()->after('location_address_snapshot');
            $table->string('fiscal_business_unit_code_snapshot', 64)->nullable()->after('fiscal_operator_code_snapshot');
            $table->string('fiscal_tcr_code_snapshot', 64)->nullable()->after('fiscal_business_unit_code_snapshot');
            $table->string('nslf', 128)->nullable()->after('customer_tax_number');
            $table->string('nivf', 128)->nullable()->after('nslf');
            $table->text('verification_url')->nullable()->after('nivf');
            $table->longText('qr_payload')->nullable()->after('verification_url');
            $table->timestamp('fiscalized_at')->nullable()->after('issued_at');
            $table->unsignedSmallInteger('fiscalization_attempts')->default(0)->after('fiscalized_at');
            $table->text('fiscalization_error')->nullable()->after('fiscalization_attempts');
            $table->index(['business_id','fiscalization_status','issued_at'], 'invoices_business_fiscal_status_idx');
            $table->index(['business_id','nivf'], 'invoices_business_nivf_idx');
        });

        Schema::table('invoice_lines', function (Blueprint $table): void {
            $table->string('unit_code_snapshot', 16)->default('C62')->after('sku_snapshot');
            $table->string('unit_label_snapshot', 64)->default('Copë')->after('unit_code_snapshot');
            $table->decimal('discount_percent', 9, 4)->default(0)->after('unit_price');
        });

        Schema::table('invoice_credit_notes', function (Blueprint $table): void {
            $table->string('fiscalization_status', 24)->default('not_fiscalized')->after('status');
            $table->string('fiscal_invoice_number', 64)->nullable()->after('fiscalization_status');
            $table->unsignedBigInteger('fiscal_ordinal_number')->nullable()->after('fiscal_invoice_number');
            $table->string('original_invoice_nslf_snapshot', 128)->nullable()->after('invoice_number_snapshot');
            $table->string('nslf', 128)->nullable()->after('reason');
            $table->string('nivf', 128)->nullable()->after('nslf');
            $table->text('verification_url')->nullable()->after('nivf');
            $table->longText('qr_payload')->nullable()->after('verification_url');
            $table->timestamp('fiscalized_at')->nullable()->after('issued_at');
            $table->unsignedSmallInteger('fiscalization_attempts')->default(0)->after('fiscalized_at');
            $table->text('fiscalization_error')->nullable()->after('fiscalization_attempts');
            $table->index(['business_id','fiscalization_status','issued_at'], 'credit_notes_business_fiscal_status_idx');
        });

        Schema::create('invoice_payment_snapshots', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('invoice_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('position');
            $table->string('method', 24);
            $table->string('method_label', 80);
            $table->decimal('amount', 18, 4);
            $table->char('currency', 3);
            $table->decimal('amount_base', 18, 4);
            $table->char('base_currency', 3);
            $table->decimal('exchange_rate', 24, 10);
            $table->string('external_reference')->nullable();
            $table->timestamps();
            $table->unique(['invoice_id','position'], 'invoice_payment_snapshots_position_uq');
            $table->index(['business_id','invoice_id'], 'invoice_payment_snapshots_business_invoice_idx');
        });

        Schema::create('invoice_fiscalization_attempts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('invoice_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('attempt_no');
            $table->string('provider', 32);
            $table->string('environment', 16);
            $table->string('status', 24);
            $table->string('request_id', 128)->nullable();
            $table->string('nslf', 128)->nullable();
            $table->string('nivf', 128)->nullable();
            $table->string('error_code', 128)->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['invoice_id','attempt_no'], 'invoice_fiscal_attempt_no_uq');
            $table->index(['business_id','status','started_at'], 'invoice_fiscal_attempt_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_fiscalization_attempts');
        Schema::dropIfExists('invoice_payment_snapshots');

        Schema::table('invoice_credit_notes', function (Blueprint $table): void {
            $table->dropIndex('credit_notes_business_fiscal_status_idx');
            $table->dropColumn([
                'fiscalization_status','fiscal_invoice_number','fiscal_ordinal_number',
                'original_invoice_nslf_snapshot','nslf','nivf','verification_url','qr_payload',
                'fiscalized_at','fiscalization_attempts','fiscalization_error',
            ]);
        });

        Schema::table('invoice_lines', function (Blueprint $table): void {
            $table->dropColumn(['unit_code_snapshot','unit_label_snapshot','discount_percent']);
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropIndex('invoices_business_fiscal_status_idx');
            $table->dropIndex('invoices_business_nivf_idx');
            $table->dropColumn([
                'fiscalization_status','fiscal_invoice_type','fiscal_invoice_number','fiscal_ordinal_number','fiscal_operator_code_snapshot',
                'fiscal_business_unit_code_snapshot','fiscal_tcr_code_snapshot','nslf','nivf',
                'verification_url','qr_payload','fiscalized_at','fiscalization_attempts','fiscalization_error',
            ]);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['unit_code','unit_label']);
        });

        Schema::table('business_user', function (Blueprint $table): void {
            $table->dropColumn('fiscal_operator_code');
        });

        Schema::table('cash_registers', function (Blueprint $table): void {
            $table->dropColumn('fiscal_tcr_code');
        });

        Schema::table('locations', function (Blueprint $table): void {
            $table->dropColumn('fiscal_business_unit_code');
        });

        Schema::dropIfExists('fiscalization_profiles');
    }
};
