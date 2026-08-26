<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_rules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('bot_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('name');

            // filter, ranking, behavior, system
            $table->string('type');

            $table->text('description')->nullable();

            $table->jsonb('config');

            $table->unsignedInteger('priority')->default(0);

            $table->boolean('is_enabled')->default(true);

            $table->timestamps();

            $table->index([
                'bot_id',
                'is_enabled',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_rules');
    }
};
