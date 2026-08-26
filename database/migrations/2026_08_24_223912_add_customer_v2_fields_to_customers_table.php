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
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('merged_into_customer_id')->nullable()->after('id')->constrained('customers')->nullOnDelete();
            $table->timestamp('merged_at')->nullable();
            $table->text('ai_summary')->nullable();
            $table->timestamp('ai_summary_generated_at')->nullable();
            $table->timestamp('ai_summary_activity_at')->nullable();
            $table->index('merged_into_customer_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['merged_into_customer_id']);
            $table->dropIndex(['merged_into_customer_id']);
            $table->dropColumn([
                'merged_into_customer_id',
                'merged_at',
                'ai_summary',
                'ai_summary_generated_at',
                'ai_summary_activity_at',
            ]);
        });
    }
};
