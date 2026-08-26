<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('team_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('bot_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('conversation_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            /*
             * message
             * openai_request
             * search
             * api_request
             * imported_record
             */
            $table->string('type');

            $table->string('provider')->nullable();

            $table->string('model')->nullable();

            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();

            $table->decimal('cost_usd', 14, 8)->nullable();

            $table->unsignedBigInteger('quantity')->default(1);

            $table->jsonb('metadata')->nullable();

            $table->timestamps();

            $table->index([
                'team_id',
                'created_at',
            ]);

            $table->index([
                'team_id',
                'type',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_events');
    }
};
