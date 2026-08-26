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
        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('channel_connection_id')->nullable()->after('bot_id')->constrained()->nullOnDelete();
            $table->index(['channel_connection_id', 'external_user_reference', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['channel_connection_id', 'external_user_reference', 'status']);
            $table->dropConstrainedForeignId('channel_connection_id');
        });
    }
};
