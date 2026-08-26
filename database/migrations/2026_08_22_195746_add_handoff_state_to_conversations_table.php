<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->string('handoff_status')->default('ai')->after('status');
            $table->string('handoff_reason')->nullable()->after('handoff_status');
            $table->timestamp('handoff_requested_at')->nullable()->after('handoff_reason');
            $table->timestamp('handoff_started_at')->nullable()->after('handoff_requested_at');
            $table->foreignId('handoff_user_id')
                ->nullable()
                ->after('handoff_started_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->index(['bot_id', 'handoff_status', 'updated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropForeign(['handoff_user_id']);
            $table->dropIndex(['bot_id', 'handoff_status', 'updated_at']);
            $table->dropColumn([
                'handoff_status',
                'handoff_reason',
                'handoff_requested_at',
                'handoff_started_at',
                'handoff_user_id',
            ]);
        });
    }
};
