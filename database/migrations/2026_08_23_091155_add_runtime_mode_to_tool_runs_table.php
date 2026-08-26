<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tool_runs', function (Blueprint $table): void {
            $table->string('runtime_mode', 20)->default('normal')->after('execution_mode');
            $table->index(['team_id', 'runtime_mode', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('tool_runs', function (Blueprint $table): void {
            $table->dropIndex('tool_runs_team_id_runtime_mode_created_at_index');
            $table->dropColumn('runtime_mode');
        });
    }
};
