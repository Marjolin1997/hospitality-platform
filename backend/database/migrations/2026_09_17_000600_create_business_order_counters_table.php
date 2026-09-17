<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('business_order_counters', function (Blueprint $table): void {
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->date('business_date');
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();

            $table->primary(['business_id', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_order_counters');
    }
};
