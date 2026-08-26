<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_states', function (Blueprint $table) {
            $table->id();

            $table->foreignId('conversation_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            $table->jsonb('active_search')->nullable();

            $table->jsonb('last_result_ids')->nullable();

            $table->jsonb('memory')->nullable();

            $table->unsignedInteger('version')->default(1);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_states');
    }
};
