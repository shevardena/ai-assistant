<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tool_runs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('visitor_id')->nullable()->constrained('widget_visitors')->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('api_operation_id')->nullable()->constrained()->nullOnDelete();

            $table->uuid('action_reference')->unique();
            $table->string('tool_name');
            $table->string('execution_mode', 10);
            $table->string('status', 30)->index();
            $table->uuid('idempotency_key')->unique();
            $table->jsonb('safe_arguments')->nullable();
            $table->jsonb('safe_result')->nullable();
            $table->string('error_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            $table->index(['team_id', 'status']);
            $table->index(['bot_id', 'status']);
            $table->index(['conversation_id', 'status']);
            $table->index(['visitor_id', 'status']);
            $table->index(['tool_name', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tool_runs');
    }
};
