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
        $now = now();
        DB::table('teams')->select('id')->orderBy('id')->chunkById(250, function ($teams) use ($now): void {
            foreach ($teams as $team) {
                $pipelineId = DB::table('pipelines')
                    ->where('team_id', $team->id)
                    ->orderByDesc('is_default')
                    ->orderBy('id')
                    ->value('id');

                if ($pipelineId === null) {
                    $pipelineId = DB::table('pipelines')->insertGetId([
                        'team_id' => $team->id,
                        'name' => 'Sales Pipeline',
                        'is_default' => true,
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                } else {
                    DB::table('pipelines')->where('id', $pipelineId)->update(['is_default' => true, 'updated_at' => $now]);
                }

                foreach ([
                    ['name' => 'New', 'sort_order' => 1, 'probability' => 10, 'semantic_type' => 'open'],
                    ['name' => 'Qualified', 'sort_order' => 2, 'probability' => 30, 'semantic_type' => 'open'],
                    ['name' => 'Proposal / Demo', 'sort_order' => 3, 'probability' => 60, 'semantic_type' => 'open'],
                    ['name' => 'Negotiation', 'sort_order' => 4, 'probability' => 80, 'semantic_type' => 'open'],
                    ['name' => 'Won', 'sort_order' => 5, 'probability' => 100, 'semantic_type' => 'won'],
                    ['name' => 'Lost', 'sort_order' => 6, 'probability' => 0, 'semantic_type' => 'lost'],
                ] as $stage) {
                    DB::table('pipeline_stages')->insertOrIgnore([
                        'team_id' => $team->id,
                        'pipeline_id' => $pipelineId,
                        ...$stage,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Default CRM data is retained when rolling back application code.
    }
};
