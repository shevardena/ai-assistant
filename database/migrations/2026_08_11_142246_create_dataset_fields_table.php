<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dataset_fields', function (Blueprint $table) {
            $table->id();

            $table->foreignId('dataset_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
             * Original source field:
             *
             * PRODUCT_NM
             * specs.storage
             */
            $table->string('source_path');

            /*
             * Safe internal name:
             *
             * product_name
             * storage_gb
             */
            $table->string('key');

            /*
             * Standard meaning:
             *
             * name
             * brand
             * category
             * price
             * storage_gb
             * ram_gb
             * stock
             * url
             * image
             */
            $table->string('canonical_name')->nullable();

            $table->string('label');

            /*
             * string
             * integer
             * decimal
             * boolean
             * date
             * datetime
             * url
             */
            $table->string('data_type');

            /*
             * price
             * percentage
             * storage
             * brand
             * category
             * image
             * url
             */
            $table->string('semantic_type')->nullable();

            $table->text('description')->nullable();

            /*
             * [
             *     "memory",
             *     "storage",
             *     "მეხსიერება"
             * ]
             */
            $table->jsonb('aliases')->nullable();

            $table->boolean('is_searchable')->default(false);
            $table->boolean('is_filterable')->default(false);
            $table->boolean('is_sortable')->default(false);
            $table->boolean('is_semantic')->default(false);
            $table->boolean('is_displayable')->default(true);

            /*
             * [
             *     "eq",
             *     "gte",
             *     "lte",
             *     "between"
             * ]
             */
            $table->jsonb('allowed_operators')->nullable();

            /*
             * gb
             * lowercase
             * percentage
             * currency
             */
            $table->string('normalizer')->nullable();

            $table->jsonb('config')->nullable();

            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            $table->unique([
                'dataset_id',
                'source_path',
            ]);

            $table->unique([
                'dataset_id',
                'key',
            ]);

            $table->index([
                'dataset_id',
                'canonical_name',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dataset_fields');
    }
};
