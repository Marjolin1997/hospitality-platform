<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('staff_invitations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('role_id')->constrained('roles')->restrictOnDelete();
            $table->foreignId('invited_by_user_id')->constrained('users')->restrictOnDelete();

            $table->string('email', 255);
            $table->string('role_name_snapshot', 120);
            $table->json('role_permissions_snapshot');
            $table->char('token_hash', 64)->unique();

            $table->string('status', 24)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'email', 'status'], 'staff_invite_business_email_status_idx');
            $table->index(['business_id', 'status', 'expires_at'], 'staff_invite_business_status_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_invitations');
    }
};
