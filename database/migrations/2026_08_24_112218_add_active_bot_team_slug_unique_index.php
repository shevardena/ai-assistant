<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE bots DROP CONSTRAINT IF EXISTS bots_team_id_slug_unique');
        DB::statement('CREATE UNIQUE INDEX bots_team_id_slug_unique ON bots (team_id, slug) WHERE deleted_at IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS bots_team_id_slug_unique');
        DB::statement('ALTER TABLE bots ADD CONSTRAINT bots_team_id_slug_unique UNIQUE (team_id, slug)');
    }
};
