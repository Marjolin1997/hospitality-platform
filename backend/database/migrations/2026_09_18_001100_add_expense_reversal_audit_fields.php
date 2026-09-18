<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->foreignUlid('reversal_of_expense_id')->nullable()->after('status')->constrained('expenses')->restrictOnDelete();
            $table->foreignId('reversed_by_user_id')->nullable()->after('reversal_of_expense_id')->constrained('users')->restrictOnDelete();
            $table->text('reversal_reason')->nullable()->after('reversed_by_user_id');
            $table->timestamp('reversed_at')->nullable()->after('reversal_reason');
            $table->index(['business_id','reversal_of_expense_id']);
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->dropForeign(['reversal_of_expense_id']);
            $table->dropForeign(['reversed_by_user_id']);
            $table->dropIndex(['business_id','reversal_of_expense_id']);
            $table->dropColumn(['reversal_of_expense_id','reversed_by_user_id','reversal_reason','reversed_at']);
        });
    }
};
