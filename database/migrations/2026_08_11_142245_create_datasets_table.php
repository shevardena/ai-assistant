<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('datasets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('team_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('data_source_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('name');
            $table->string('slug');

            // product, car, hotel, property, generic...
            $table->string('entity_type')->default('generic');

            /*
             * indexed
             * live
             * hybrid
             */
            $table->string('retrieval_mode')
                ->default('indexed');

            /*
             * Example:
             *
             * id
             * sku
             * product.id
             */
            $table->string('primary_key_path')->nullable();

            // preparing, ready, indexing, error
            $table->string('status')->default('preparing');

            $table->jsonb('settings')->nullable();

            $table->unsignedInteger('schema_version')->default(1);

            $table->timestamp('last_indexed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique([
                'team_id',
                'slug',
            ]);

            $table->index([
                'team_id',
                'status',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('datasets');
    }
};
