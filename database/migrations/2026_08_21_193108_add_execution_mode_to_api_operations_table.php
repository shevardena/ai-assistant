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
        Schema::table('api_operations', function (Blueprint $table) {
            $table->string('execution_mode', 10)
                ->default('read')
                ->after('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('api_operations', function (Blueprint $table) {
            $table->dropColumn('execution_mode');
        });
    }
};
