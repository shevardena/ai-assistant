<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_sources', function (Blueprint $table) {
            $table->id();

            $table->foreignId('team_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('name');

            // file, rest_api
            $table->string('type');

            // pending, ready, syncing, error, disabled
            $table->string('status')->default('pending');

            /*
             * Non-secret configuration.
             *
             * Example:
             *
             * {
             *   "base_url": "https://api.example.com"
             * }
             */
            $table->jsonb('config')->nullable();

            $table->timestamp('last_synced_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['team_id', 'type']);
            $table->index(['team_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_sources');
    }
};
