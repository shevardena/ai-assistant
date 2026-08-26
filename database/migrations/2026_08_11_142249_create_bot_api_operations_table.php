<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_api_operations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('bot_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('api_operation_id')
                ->constrained()
                ->cascadeOnDelete();

            // Example: search_products
            $table->string('tool_name');

            $table->boolean('is_enabled')->default(true);

            $table->jsonb('settings')->nullable();

            $table->timestamps();

            $table->unique([
                'bot_id',
                'api_operation_id',
            ]);

            $table->unique([
                'bot_id',
                'tool_name',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_api_operations');
    }
};
