<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('display_name')->nullable();
            $table->string('email', 320)->nullable();
            $table->string('normalized_email', 320)->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('normalized_phone', 64)->nullable();
            $table->string('company')->nullable();
            $table->string('status', 20)->default('new');
            $table->string('source', 30)->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
            $table->index('team_id');
            $table->index(['team_id', 'normalized_email']);
            $table->index(['team_id', 'normalized_phone']);
            $table->index(['team_id', 'status']);
            $table->index(['team_id', 'owner_id']);
            $table->index(['team_id', 'last_activity_at']);
            $table->unique(['team_id', 'normalized_email']);
            $table->unique(['team_id', 'normalized_phone']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
