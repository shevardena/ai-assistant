<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('bots')
            ->select(['id', 'team_id'])
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get()
            ->each(function (object $bot): void {
                DB::table('channel_connections')->insertOrIgnore([
                    'public_id' => (string) Str::uuid(),
                    'team_id' => $bot->team_id,
                    'bot_id' => $bot->id,
                    'channel' => 'website',
                    'name' => 'Website',
                    'status' => 'draft',
                    'configuration' => json_encode([
                        'managed_by' => 'website_widget',
                        'domains_source' => 'bot_domains',
                        'provisioned_by' => 'migration',
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('channel_connections')
            ->where('channel', 'website')
            ->whereJsonContains('configuration->provisioned_by', 'migration')
            ->delete();
    }
};
