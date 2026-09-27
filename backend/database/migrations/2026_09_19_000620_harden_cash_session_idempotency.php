<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('cash_sessions', function (Blueprint $table): void {
            $table->string('open_idempotency_key', 64)->nullable()->after('cash_register_id');
            $table->json('open_request_snapshot')->nullable()->after('open_idempotency_key');
            $table->string('close_idempotency_key', 64)->nullable()->after('closing_note');
            $table->json('close_request_snapshot')->nullable()->after('close_idempotency_key');

            $table->unique(['business_id', 'open_idempotency_key'], 'cash_session_business_open_idem_uq');
            $table->unique(['business_id', 'close_idempotency_key'], 'cash_session_business_close_idem_uq');
        });
    }

    public function down(): void
    {
        Schema::table('cash_sessions', function (Blueprint $table): void {
            $table->dropUnique('cash_session_business_open_idem_uq');
            $table->dropUnique('cash_session_business_close_idem_uq');
            $table->dropColumn([
                'open_idempotency_key',
                'open_request_snapshot',
                'close_idempotency_key',
                'close_request_snapshot',
            ]);
        });
    }
};
