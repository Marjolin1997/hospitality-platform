<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('staff_invitations', function (Blueprint $table): void {
            $table->timestamp('expired_at')->nullable()->after('expires_at');
            $table->unsignedSmallInteger('reissue_count')->default(0)->after('expired_at');
            $table->timestamp('last_reissued_at')->nullable()->after('reissue_count');
            $table->foreignId('last_reissued_by_user_id')->nullable()->after('last_reissued_at')
                ->constrained('users')->restrictOnDelete();
        });

        Schema::create('staff_invitation_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('staff_invitation_id')->constrained('staff_invitations')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('event', 32);
            $table->string('previous_status', 24)->nullable();
            $table->string('new_status', 24);
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['business_id', 'occurred_at'], 'staff_invite_event_business_time_idx');
            $table->index(['staff_invitation_id', 'occurred_at'], 'staff_invite_event_invite_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_invitation_events');

        Schema::table('staff_invitations', function (Blueprint $table): void {
            $table->dropForeign(['last_reissued_by_user_id']);
            $table->dropColumn([
                'expired_at',
                'reissue_count',
                'last_reissued_at',
                'last_reissued_by_user_id',
            ]);
        });
    }
};
