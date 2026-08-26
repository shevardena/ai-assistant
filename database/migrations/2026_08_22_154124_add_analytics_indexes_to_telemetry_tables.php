<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->index(['bot_id', 'created_at'], 'conversations_bot_created_at_index');
        });

        Schema::table('tool_runs', function (Blueprint $table): void {
            $table->index(['team_id', 'bot_id', 'created_at'], 'tool_runs_team_bot_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropIndex('conversations_bot_created_at_index');
        });

        Schema::table('tool_runs', function (Blueprint $table): void {
            $table->dropIndex('tool_runs_team_bot_created_at_index');
        });
    }
};
