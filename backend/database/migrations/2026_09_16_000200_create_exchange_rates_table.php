<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table): void {
            $table->id();
            $table->char('base_currency', 3);
            $table->char('quote_currency', 3);
            $table->decimal('rate', 20, 10);
            $table->string('source', 64);
            $table->timestamp('effective_at');
            $table->timestamp('fetched_at');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['base_currency', 'quote_currency', 'source', 'effective_at'], 'exchange_rates_unique');
            $table->index(['base_currency', 'quote_currency', 'effective_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
