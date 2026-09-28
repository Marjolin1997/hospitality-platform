<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            if (! Schema::hasColumn('products', 'unit_code')) {
                $table->string('unit_code', 16)->default('C62')->after('tax_rate');
            }

            if (! Schema::hasColumn('products', 'unit_label')) {
                $table->string('unit_label', 64)->default('Copë')->after('unit_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $columns = [];
            if (Schema::hasColumn('products', 'unit_label')) {
                $columns[] = 'unit_label';
            }
            if (Schema::hasColumn('products', 'unit_code')) {
                $columns[] = 'unit_code';
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
