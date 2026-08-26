<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_runs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('data_source_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('dataset_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // import, sync, reindex, schema_detection
            $table->string('type');

            // pending, running, completed, failed
            $table->string('status')->default('pending');

            $table->unsignedBigInteger('rows_read')->default(0);
            $table->unsignedBigInteger('rows_written')->default(0);
            $table->unsignedBigInteger('rows_failed')->default(0);

            $table->text('error')->nullable();

            $table->jsonb('metadata')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            $table->index([
                'data_source_id',
                'status',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_runs');
    }
};
