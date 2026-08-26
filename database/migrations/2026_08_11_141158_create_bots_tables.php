<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bots', function (Blueprint $table) {
            $table->id();

            $table->foreignId('team_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->uuid('public_id')->unique();

            $table->string('name');
            $table->string('slug');

            // draft, published, disabled
            $table->string('status')->default('draft');

            $table->string('default_language', 10)->default('en');

            $table->text('instructions')->nullable();
            $table->text('welcome_message')->nullable();
            $table->text('fallback_message')->nullable();

            $table->jsonb('settings')->nullable();
            $table->jsonb('appearance')->nullable();

            $table->timestamp('published_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['team_id', 'slug']);
            $table->index(['team_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bots');
    }
};
