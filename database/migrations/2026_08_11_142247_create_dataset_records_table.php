<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dataset_records', function (Blueprint $table) {
            $table->id();

            $table->foreignId('dataset_id')
                ->constrained()
                ->cascadeOnDelete();

            // SKU, source ID, generated stable ID...
            $table->string('external_id');

            $table->jsonb('payload');

            $table->text('searchable_text')->nullable();

            $table->string('checksum')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamp('source_updated_at')->nullable();

            $table->timestamps();

            $table->unique([
                'dataset_id',
                'external_id',
            ]);

            $table->index([
                'dataset_id',
                'is_active',
            ]);
        });

        DB::statement(
            'CREATE INDEX dataset_records_payload_gin
             ON dataset_records
             USING GIN (payload)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('dataset_records');
    }
};
