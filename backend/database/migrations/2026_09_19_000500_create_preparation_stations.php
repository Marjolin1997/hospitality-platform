<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('preparation_stations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('code', 32);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['business_id', 'code'], 'prep_station_business_code_uq');
            $table->unique(['business_id', 'name'], 'prep_station_business_name_uq');
            $table->index(['business_id', 'is_active', 'sort_order'], 'prep_station_business_active_sort_idx');
        });

        $existing = DB::table('products')
            ->whereNotNull('preparation_station')
            ->where('preparation_station', '!=', '')
            ->get(['business_id', 'preparation_station']);

        $seen = [];
        foreach ($existing as $row) {
            $code = Str::lower(trim((string) $row->preparation_station));
            if ($code === '') {
                continue;
            }

            $key = $row->business_id.'|'.$code;
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            DB::table('preparation_stations')->insert([
                'id' => (string) Str::ulid(),
                'business_id' => $row->business_id,
                'name' => Str::headline(str_replace(['-', '_'], ' ', $code)),
                'code' => $code,
                'sort_order' => $code === 'bar' ? 10 : ($code === 'kitchen' ? 20 : 100),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('preparation_stations');
    }
};
