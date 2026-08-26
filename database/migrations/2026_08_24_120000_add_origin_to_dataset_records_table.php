<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dataset_records', function (Blueprint $table): void {
            $table->string('origin')->default('manual')->after('external_id');
            $table->index(['dataset_id', 'origin', 'is_active']);
        });

        DB::statement(<<<'SQL'
            UPDATE dataset_records AS records
            SET origin = CASE data_sources.type
                WHEN 'file' THEN 'file_import'
                WHEN 'rest_api' THEN 'rest_api'
                WHEN 'graphql_api' THEN 'graphql_api'
                ELSE 'manual'
            END
            FROM datasets
            LEFT JOIN data_sources ON data_sources.id = datasets.data_source_id
            WHERE records.dataset_id = datasets.id
        SQL);
    }

    public function down(): void
    {
        Schema::table('dataset_records', function (Blueprint $table): void {
            $table->dropIndex(['dataset_id', 'origin', 'is_active']);
            $table->dropColumn('origin');
        });
    }
};
