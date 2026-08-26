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
        Schema::create('channel_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider');
            $table->longText('encrypted_access_token');
            $table->longText('encrypted_verify_token');
            $table->longText('encrypted_app_secret')->nullable();
            $table->string('verify_token_hash', 64)->index();
            $table->string('access_token_last_four', 4)->nullable();
            $table->timestamps();

            $table->unique(['channel_connection_id', 'provider']);
            $table->index(['team_id', 'provider']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('channel_credentials');
    }
};
