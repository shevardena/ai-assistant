<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_gaps', function (Blueprint $table) {
            $table->id();

            $table->foreignId('team_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('bot_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('conversation_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('message_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('resolved_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('reason');
            $table->string('normalized_question', 500);
            $table->char('normalized_hash', 64);
            $table->char('group_reference', 64);
            $table->string('status')->default('open');
            $table->timestampTz('resolved_at')->nullable();

            $table->timestamps();

            $table->index(['team_id', 'bot_id', 'normalized_hash']);
            $table->index(['team_id', 'group_reference']);
            $table->index(['team_id', 'status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('knowledge_gaps');
    }
};
