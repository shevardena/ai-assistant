<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('conversation_id')
                ->constrained()
                ->cascadeOnDelete();

            // user, assistant, tool, system
            $table->string('role');

            // text, cards, comparison, tool_call
            $table->string('type')->default('text');

            $table->longText('content')->nullable();

            $table->string('tool_name')->nullable();

            $table->string('tool_call_id')->nullable();

            $table->jsonb('tool_calls')->nullable();

            $table->jsonb('tool_result')->nullable();

            $table->jsonb('metadata')->nullable();

            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();

            $table->timestamps();

            $table->index([
                'conversation_id',
                'created_at',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
