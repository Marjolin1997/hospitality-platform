<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            $table->unique(['business_id','fiscal_business_unit_code'], 'locations_business_fiscal_unit_uq');
        });

        Schema::table('cash_registers', function (Blueprint $table): void {
            $table->unique(['business_id','fiscal_tcr_code'], 'registers_business_fiscal_tcr_uq');
        });

        Schema::table('business_user', function (Blueprint $table): void {
            $table->unique(['business_id','fiscal_operator_code'], 'memberships_business_fiscal_operator_uq');
        });
    }

    public function down(): void
    {
        Schema::table('business_user', function (Blueprint $table): void {
            $table->dropUnique('memberships_business_fiscal_operator_uq');
        });

        Schema::table('cash_registers', function (Blueprint $table): void {
            $table->dropUnique('registers_business_fiscal_tcr_uq');
        });

        Schema::table('locations', function (Blueprint $table): void {
            $table->dropUnique('locations_business_fiscal_unit_uq');
        });
    }
};
