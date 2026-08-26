<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pipeline_stages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pipeline_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('sort_order');
            $table->decimal('probability', 5, 2)->nullable();
            $table->string('semantic_type', 10)->default('open');
            $table->timestamps();
            $table->index(['team_id', 'pipeline_id', 'sort_order']);
            $table->unique(['pipeline_id', 'name']);
        });

        DB::statement("CREATE UNIQUE INDEX pipeline_stages_one_won_per_pipeline ON pipeline_stages (pipeline_id) WHERE semantic_type = 'won'");
        DB::statement("CREATE UNIQUE INDEX pipeline_stages_one_lost_per_pipeline ON pipeline_stages (pipeline_id) WHERE semantic_type = 'lost'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS pipeline_stages_one_won_per_pipeline');
        DB::statement('DROP INDEX IF EXISTS pipeline_stages_one_lost_per_pipeline');
        Schema::dropIfExists('pipeline_stages');
    }
};
