<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('business_role_audits', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->restrictOnDelete();
            $table->ulid('role_id')->nullable();
            $table->foreignId('performed_by_user_id')->constrained('users')->restrictOnDelete();

            $table->string('role_slug', 255);
            $table->string('previous_name', 120)->nullable();
            $table->string('new_name', 120)->nullable();
            $table->json('previous_permissions')->nullable();
            $table->json('new_permissions')->nullable();
            $table->string('action', 24);
            $table->timestamp('performed_at');
            $table->timestamps();

            $table->index(['business_id', 'performed_at'], 'role_audit_business_time_idx');
            $table->index(['business_id', 'role_id', 'performed_at'], 'role_audit_role_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_role_audits');
    }
};
