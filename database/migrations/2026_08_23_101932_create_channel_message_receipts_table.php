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
        Schema::create('channel_message_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_connection_id')->constrained()->cascadeOnDelete();
            $table->string('external_message_reference');
            $table->string('status')->default('received');
            $table->timestampTz('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['channel_connection_id', 'external_message_reference']);
            $table->index(['team_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('channel_message_receipts');
    }
};
