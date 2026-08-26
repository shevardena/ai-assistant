<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_datasets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('bot_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('dataset_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->unsignedInteger('priority')->default(0);

            $table->boolean('is_enabled')->default(true);

            $table->jsonb('settings')->nullable();

            $table->timestamps();

            $table->unique([
                'bot_id',
                'dataset_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_datasets');
    }
};
