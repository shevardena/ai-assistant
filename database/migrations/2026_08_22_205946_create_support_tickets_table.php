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
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tool_run_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('open');
            $table->string('subject')->nullable();
            $table->text('summary')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_email', 320)->nullable();
            $table->string('provider_reference')->nullable();
            $table->text('external_url')->nullable();
            $table->timestamps();
            $table->index(['team_id', 'created_at']);
            $table->index(['team_id', 'status', 'created_at']);
            $table->index(['bot_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
};
