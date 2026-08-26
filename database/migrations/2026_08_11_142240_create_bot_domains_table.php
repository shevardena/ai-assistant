<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_domains', function (Blueprint $table) {
            $table->id();

            $table->foreignId('bot_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('domain');

            $table->boolean('is_active')->default(true);

            $table->timestamp('verified_at')->nullable();

            $table->timestamps();

            $table->unique(['bot_id', 'domain']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_domains');
    }
};
