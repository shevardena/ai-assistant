<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_card_templates', function (Blueprint $table) {
            $table->id();

            $table->foreignId('bot_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('dataset_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('name')->default('Default');

            $table->boolean('is_default')->default(false);

            /*
             * {
             *     "title": "product_name",
             *     "image": "image",
             *     "price": "price",
             *     "old_price": "old_price",
             *     "url": "url"
             * }
             */
            $table->jsonb('mapping');

            $table->jsonb('layout')->nullable();

            $table->timestamps();

            $table->index([
                'bot_id',
                'is_default',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_card_templates');
    }
};
