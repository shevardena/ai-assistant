<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_operation_sync_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('api_operation_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('dataset_id')->nullable()->constrained()->nullOnDelete();
            $table->string('frequency')->default('manual');
            $table->string('strategy')->default('full_snapshot');
            $table->boolean('is_enabled')->default(false);
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_completed_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->jsonb('checkpoint')->nullable();
            $table->jsonb('configuration')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['is_enabled', 'next_run_at']);
            $table->index(['locked_until']);
        });

        $operations = DB::table('api_operations')
            ->select(['id', 'data_source_id', 'response_mapping'])
            ->get();
        foreach ($operations as $operation) {
            $mapping = is_string($operation->response_mapping)
                ? json_decode($operation->response_mapping, true)
                : $operation->response_mapping;

            if (! is_array($mapping) || ($mapping['sync_mode'] ?? null) !== 'full_snapshot') {
                continue;
            }

            DB::table('api_operation_sync_schedules')->insert([
                'api_operation_id' => $operation->id,
                'dataset_id' => null,
                'frequency' => 'manual',
                'strategy' => 'full_snapshot',
                'is_enabled' => false,
                'configuration' => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('api_operation_sync_schedules');
    }
};
