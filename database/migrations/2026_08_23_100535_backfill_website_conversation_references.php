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
        DB::table('conversations')
            ->where('channel', 'website')
            ->whereNotNull('visitor_id')
            ->whereJsonContains('metadata->source', 'widget')
            ->whereNull('external_user_reference')
            ->update([
                'external_user_reference' => DB::raw(
                    '(SELECT public_id FROM widget_visitors WHERE widget_visitors.id = conversations.visitor_id)',
                ),
                'external_conversation_reference' => DB::raw('public_id'),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Existing external references cannot be distinguished from values created after this backfill.
    }
};
