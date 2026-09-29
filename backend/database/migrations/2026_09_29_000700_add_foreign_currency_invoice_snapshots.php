<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->char('invoice_currency', 3)->nullable()->after('currency');
            $table->decimal('exchange_rate', 20, 10)->nullable()->after('invoice_currency');
            $table->string('exchange_rate_source', 64)->nullable()->after('exchange_rate');
            $table->timestamp('exchange_rate_effective_at')->nullable()->after('exchange_rate_source');
            $table->decimal('subtotal_foreign', 18, 4)->nullable()->after('grand_total');
            $table->decimal('discount_total_foreign', 18, 4)->nullable()->after('subtotal_foreign');
            $table->decimal('tax_total_foreign', 18, 4)->nullable()->after('discount_total_foreign');
            $table->decimal('grand_total_foreign', 18, 4)->nullable()->after('tax_total_foreign');
        });

        Schema::table('invoice_lines', function (Blueprint $table): void {
            $table->decimal('unit_price_foreign', 18, 4)->nullable()->after('unit_price');
            $table->decimal('line_subtotal_foreign', 18, 4)->nullable()->after('line_total');
            $table->decimal('line_tax_foreign', 18, 4)->nullable()->after('line_subtotal_foreign');
            $table->decimal('line_total_foreign', 18, 4)->nullable()->after('line_tax_foreign');
        });

        Schema::table('invoice_credit_notes', function (Blueprint $table): void {
            $table->char('invoice_currency', 3)->nullable()->after('currency');
            $table->decimal('exchange_rate', 20, 10)->nullable()->after('invoice_currency');
            $table->string('exchange_rate_source', 64)->nullable()->after('exchange_rate');
            $table->timestamp('exchange_rate_effective_at')->nullable()->after('exchange_rate_source');
            $table->decimal('subtotal_foreign', 18, 4)->nullable()->after('grand_total');
            $table->decimal('discount_total_foreign', 18, 4)->nullable()->after('subtotal_foreign');
            $table->decimal('tax_total_foreign', 18, 4)->nullable()->after('discount_total_foreign');
            $table->decimal('grand_total_foreign', 18, 4)->nullable()->after('tax_total_foreign');
        });

        Schema::table('invoice_credit_note_lines', function (Blueprint $table): void {
            $table->decimal('unit_price_foreign', 18, 4)->nullable()->after('unit_price');
            $table->decimal('line_subtotal_foreign', 18, 4)->nullable()->after('line_total');
            $table->decimal('line_tax_foreign', 18, 4)->nullable()->after('line_subtotal_foreign');
            $table->decimal('line_total_foreign', 18, 4)->nullable()->after('line_tax_foreign');
        });

        DB::table('invoices')->whereNull('invoice_currency')->update([
            'invoice_currency' => DB::raw('currency'),
        ]);
        DB::table('invoice_credit_notes')->whereNull('invoice_currency')->update([
            'invoice_currency' => DB::raw('currency'),
        ]);
    }

    public function down(): void
    {
        Schema::table('invoice_credit_note_lines', function (Blueprint $table): void {
            $table->dropColumn([
                'unit_price_foreign','line_subtotal_foreign','line_tax_foreign','line_total_foreign',
            ]);
        });

        Schema::table('invoice_credit_notes', function (Blueprint $table): void {
            $table->dropColumn([
                'invoice_currency','exchange_rate','exchange_rate_source','exchange_rate_effective_at',
                'subtotal_foreign','discount_total_foreign','tax_total_foreign','grand_total_foreign',
            ]);
        });

        Schema::table('invoice_lines', function (Blueprint $table): void {
            $table->dropColumn([
                'unit_price_foreign','line_subtotal_foreign','line_tax_foreign','line_total_foreign',
            ]);
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn([
                'invoice_currency','exchange_rate','exchange_rate_source','exchange_rate_effective_at',
                'subtotal_foreign','discount_total_foreign','tax_total_foreign','grand_total_foreign',
            ]);
        });
    }
};
