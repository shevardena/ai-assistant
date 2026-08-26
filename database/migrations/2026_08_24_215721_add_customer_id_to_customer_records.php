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
        foreach (['conversations', 'leads', 'appointments', 'support_tickets'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('customer_id')->nullable()->after('id')->constrained()->nullOnDelete();
                $table->index(['customer_id', 'created_at']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['conversations', 'leads', 'appointments', 'support_tickets'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropForeign(['customer_id']);
                $table->dropIndex(['customer_id', 'created_at']);
                $table->dropColumn('customer_id');
            });
        }
    }
};
