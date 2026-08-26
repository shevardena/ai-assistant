<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_files', function (Blueprint $table) {
            $table->id();

            $table->foreignId('data_source_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('uploaded_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('disk')->default('local');

            $table->text('path');

            $table->string('original_name');

            $table->string('mime_type')->nullable();

            $table->unsignedBigInteger('size_bytes')->nullable();

            $table->string('checksum')->nullable();

            // uploaded, processing, ready, failed
            $table->string('status')->default('uploaded');

            $table->jsonb('metadata')->nullable();

            $table->timestamps();

            $table->index([
                'data_source_id',
                'status',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_files');
    }
};
