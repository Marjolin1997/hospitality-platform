<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('business_membership_audits', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('target_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('performed_by_user_id')->constrained('users')->restrictOnDelete();

            $table->ulid('previous_role_id')->nullable();
            $table->string('previous_role_name', 120)->nullable();
            $table->string('previous_role_slug', 120)->nullable();
            $table->string('previous_status', 24);

            $table->ulid('new_role_id')->nullable();
            $table->string('new_role_name', 120)->nullable();
            $table->string('new_role_slug', 120)->nullable();
            $table->string('new_status', 24);

            $table->string('action', 32);
            $table->timestamp('performed_at');
            $table->timestamps();

            $table->index(['business_id', 'performed_at'], 'membership_audit_business_time_idx');
            $table->index(['business_id', 'target_user_id', 'performed_at'], 'membership_audit_target_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_membership_audits');
    }
};
