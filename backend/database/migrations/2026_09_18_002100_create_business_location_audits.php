<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('business_location_audits', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->restrictOnDelete();
            $table->ulid('location_id');
            $table->foreignId('performed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 32);
            $table->json('previous_state')->nullable();
            $table->json('new_state')->nullable();
            $table->timestamp('performed_at');
            $table->timestamps();

            $table->index(['business_id', 'performed_at'], 'location_audit_business_time_idx');
            $table->index(['business_id', 'location_id', 'performed_at'], 'location_audit_location_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_location_audits');
    }
};
