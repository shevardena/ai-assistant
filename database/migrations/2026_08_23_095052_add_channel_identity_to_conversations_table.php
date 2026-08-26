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
            $table->string('channel')->default('website')->after('visitor_id');
            $table->string('external_user_reference')->nullable()->after('channel');
            $table->string('external_conversation_reference')->nullable()->after('external_user_reference');
            $table->index(['bot_id', 'channel', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['bot_id', 'channel', 'created_at']);
            $table->dropColumn([
                'channel',
                'external_user_reference',
                'external_conversation_reference',
            ]);
        });
    }
};
