<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_card_templates', function (Blueprint $table): void {
            $table->foreignId('api_operation_id')
                ->nullable()
                ->after('dataset_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bot_card_templates', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('api_operation_id');
        });
    }
};
