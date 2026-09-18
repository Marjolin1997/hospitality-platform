<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('fiscalization_profiles', function (Blueprint $table): void {
            $table->string('certificate_password_secret_ref')->nullable()->after('certificate_secret_ref');
            $table->boolean('is_issuer_in_vat')->nullable()->after('certificate_password_secret_ref');
        });

        Schema::create('business_fiscal_invoice_counters', function (Blueprint $table): void {
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('fiscal_year');
            $table->string('scope_key', 64);
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();
            $table->primary(['business_id','fiscal_year','scope_key'], 'fiscal_invoice_counter_pk');
        });

        Schema::table('invoice_fiscalization_attempts', function (Blueprint $table): void {
            $table->char('payload_hash', 64)->nullable()->after('request_id');
            $table->boolean('retryable')->default(false)->after('status');
            $table->timestamp('next_retry_at')->nullable()->after('retryable');
            $table->unsignedSmallInteger('http_status')->nullable()->after('next_retry_at');
            $table->index(['status','retryable','next_retry_at'], 'fiscal_attempt_retry_queue_idx');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_fiscalization_attempts', function (Blueprint $table): void {
            $table->dropIndex('fiscal_attempt_retry_queue_idx');
            $table->dropColumn(['payload_hash','retryable','next_retry_at','http_status']);
        });

        Schema::dropIfExists('business_fiscal_invoice_counters');

        Schema::table('fiscalization_profiles', function (Blueprint $table): void {
            $table->dropColumn(['certificate_password_secret_ref','is_issuer_in_vat']);
        });
    }
};
