<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_runs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('bot_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('conversation_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('message_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('dataset_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->text('query');

            /*
             * OpenAI interpretation:
             *
             * {
             *     "category": "mobile_phone",
             *     "storage_gb": 512,
             *     "max_price": 500
             * }
             */
            $table->jsonb('intent')->nullable();

            // typesense, postgres, rest_api...
            $table->string('adapter')->nullable();

            $table->jsonb('request_payload')->nullable();

            $table->unsignedInteger('result_count')->default(0);

            $table->unsignedInteger('latency_ms')->nullable();

            // success, failed, validation_failed
            $table->string('status');

            $table->text('error')->nullable();

            $table->timestamps();

            $table->index([
                'bot_id',
                'created_at',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_runs');
    }
};
