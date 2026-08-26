<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('widget_visitors', function (Blueprint $table) {
            $table->id();

            $table->foreignId('bot_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->uuid('public_id')->unique();

            /*
             * Client's own logged-in customer ID,
             * if supplied securely later.
             */
            $table->string('external_customer_id')->nullable();

            $table->jsonb('attributes')->nullable();

            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            $table->timestamps();

            $table->index([
                'bot_id',
                'external_customer_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widget_visitors');
    }
};
