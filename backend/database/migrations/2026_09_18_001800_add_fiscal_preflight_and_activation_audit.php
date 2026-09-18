<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('fiscalization_profiles', function (Blueprint $table): void {
            $table->timestamp('last_test_verified_at')->nullable()->after('last_verified_at');
            $table->timestamp('last_production_verified_at')->nullable()->after('last_test_verified_at');
            $table->timestamp('production_activated_at')->nullable()->after('last_production_verified_at');
            $table->unsignedBigInteger('production_activated_by_user_id')->nullable()->after('production_activated_at');
            $table->timestamp('preflight_checked_at')->nullable()->after('production_activated_by_user_id');
            $table->string('preflight_status', 24)->nullable()->after('preflight_checked_at');
            $table->timestamp('certificate_not_before')->nullable()->after('preflight_status');
            $table->timestamp('certificate_not_after')->nullable()->after('certificate_not_before');
            $table->char('certificate_fingerprint_sha256', 64)->nullable()->after('certificate_not_after');

            $table->foreign('production_activated_by_user_id', 'fiscal_profile_activated_by_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fiscalization_profiles', function (Blueprint $table): void {
            $table->dropForeign('fiscal_profile_activated_by_fk');
            $table->dropColumn([
                'last_test_verified_at',
                'last_production_verified_at',
                'production_activated_at',
                'production_activated_by_user_id',
                'preflight_checked_at',
                'preflight_status',
                'certificate_not_before',
                'certificate_not_after',
                'certificate_fingerprint_sha256',
            ]);
        });
    }
};
