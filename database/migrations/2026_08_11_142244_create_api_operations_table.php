<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_operations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('data_source_id')
                ->constrained()
                ->cascadeOnDelete();

            // search_products
            $table->string('key');

            $table->string('name');

            // search, detail, action
            $table->string('type');

            $table->string('method', 10);

            // /products
            $table->string('path');

            /*
             * Defines supported parameters.
             */
            $table->jsonb('request_schema')->nullable();

            /*
             * Internal field -> external API parameter
             */
            $table->jsonb('request_mapping')->nullable();

            /*
             * External response -> canonical result
             */
            $table->jsonb('response_mapping')->nullable();

            $table->jsonb('headers')->nullable();

            $table->unsignedInteger('timeout_ms')->default(10000);

            $table->boolean('is_enabled')->default(true);

            $table->timestamps();

            $table->unique([
                'data_source_id',
                'key',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_operations');
    }
};
