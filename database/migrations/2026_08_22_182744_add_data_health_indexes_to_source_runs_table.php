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
        Schema::table('source_runs', function (Blueprint $table) {
            $table->index(
                ['dataset_id', 'status', 'finished_at'],
                'source_runs_dataset_status_finished_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('source_runs', function (Blueprint $table) {
            $table->dropIndex('source_runs_dataset_status_finished_index');
        });
    }
};
