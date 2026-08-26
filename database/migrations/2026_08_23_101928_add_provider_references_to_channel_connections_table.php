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
        Schema::table('channel_connections', function (Blueprint $table) {
            $table->string('provider_account_reference')->nullable()->after('channel');
            $table->string('provider_channel_reference')->nullable()->after('provider_account_reference');
            $table->unique(['channel', 'provider_channel_reference']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channel_connections', function (Blueprint $table) {
            $table->dropUnique(['channel', 'provider_channel_reference']);
            $table->dropColumn(['provider_account_reference', 'provider_channel_reference']);
        });
    }
};
