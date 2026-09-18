<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('business_invoice_counters', function (Blueprint $table): void {
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->date('business_date');
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();
            $table->primary(['business_id', 'business_date']);
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('order_number_snapshot', 64)->nullable()->after('order_id');
            $table->decimal('discount_total', 18, 4)->default(0)->after('subtotal');
            $table->string('business_name_snapshot')->nullable()->after('created_by_user_id');
            $table->string('business_legal_name_snapshot')->nullable()->after('business_name_snapshot');
            $table->string('business_tax_number_snapshot')->nullable()->after('business_legal_name_snapshot');
            $table->string('location_name_snapshot')->nullable()->after('business_tax_number_snapshot');
            $table->string('location_address_snapshot')->nullable()->after('location_name_snapshot');
        });

        Schema::create('invoice_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('invoice_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('position');
            $table->string('product_name_snapshot');
            $table->string('sku_snapshot', 64)->nullable();
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('tax_rate', 9, 4);
            $table->decimal('line_subtotal', 18, 4);
            $table->decimal('line_tax', 18, 4);
            $table->decimal('line_total', 18, 4);
            $table->timestamps();
            $table->unique(['invoice_id', 'position']);
            $table->index(['business_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn([
                'order_number_snapshot','discount_total','business_name_snapshot','business_legal_name_snapshot',
                'business_tax_number_snapshot','location_name_snapshot','location_address_snapshot',
            ]);
        });
        Schema::dropIfExists('business_invoice_counters');
    }
};
