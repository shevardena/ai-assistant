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
            $table->string('conversation_status')->default('open')->after('status');
            $table->foreignId('assigned_to_user_id')
                ->nullable()
                ->after('conversation_status')
                ->constrained('users')
                ->nullOnDelete();
            $table->index(['bot_id', 'conversation_status', 'updated_at']);
            $table->index(['assigned_to_user_id', 'conversation_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropForeign(['assigned_to_user_id']);
            $table->dropIndex(['bot_id', 'conversation_status', 'updated_at']);
            $table->dropIndex(['assigned_to_user_id', 'conversation_status']);
            $table->dropColumn(['conversation_status', 'assigned_to_user_id']);
        });
    }
};
