<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('venue_areas', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->after('sort_order');
            $table->index(['business_id', 'location_id', 'is_active', 'sort_order'], 'venue_areas_location_active_sort_idx');
        });

        Schema::create('business_configuration_audits', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('location_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('performed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('entity_type', 40);
            $table->ulid('entity_id');
            $table->string('action', 32);
            $table->json('previous_state')->nullable();
            $table->json('new_state')->nullable();
            $table->timestamp('performed_at');
            $table->timestamps();

            $table->index(['business_id', 'performed_at'], 'config_audit_business_time_idx');
            $table->index(['business_id', 'entity_type', 'entity_id'], 'config_audit_entity_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_configuration_audits');

        Schema::table('venue_areas', function (Blueprint $table): void {
            $table->dropIndex('venue_areas_location_active_sort_idx');
            $table->dropColumn('is_active');
        });
    }
};
