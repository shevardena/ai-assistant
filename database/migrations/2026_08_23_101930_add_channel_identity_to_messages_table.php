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
        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('channel_connection_id')->nullable()->after('conversation_id')->constrained()->nullOnDelete();
            $table->string('external_message_reference')->nullable()->after('channel_connection_id');
            $table->index(['channel_connection_id', 'external_message_reference']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['channel_connection_id', 'external_message_reference']);
            $table->dropConstrainedForeignId('channel_connection_id');
            $table->dropColumn('external_message_reference');
        });
    }
};
