<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();

            $table->uuid('public_id')->unique();

            $table->foreignId('bot_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('visitor_id')
                ->nullable()
                ->constrained('widget_visitors')
                ->nullOnDelete();

            // active, closed, archived
            $table->string('status')->default('active');

            $table->string('language', 10)->nullable();

            $table->string('openai_conversation_id')
                ->nullable();

            $table->longText('summary')->nullable();

            $table->jsonb('metadata')->nullable();

            $table->timestamp('last_message_at')->nullable();

            $table->timestamps();

            $table->index([
                'bot_id',
                'status',
            ]);

            $table->index('openai_conversation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
