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
        Schema::create('workflows', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('description', 1000)->nullable();
            $table->string('status', 32)->default('draft');
            $table->string('trigger_type', 64);
            $table->boolean('is_enabled')->default(false);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['team_id', 'status', 'trigger_type']);
        });

        Schema::create('workflow_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->string('type', 64);
            $table->string('operator', 32);
            $table->jsonb('value');
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->unique(['workflow_id', 'position']);
        });

        Schema::create('workflow_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->string('type', 64);
            $table->jsonb('config')->nullable();
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->unique(['workflow_id', 'position']);
        });

        Schema::create('workflow_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->string('trigger_type', 64);
            $table->string('status', 32);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('trigger_reference', 255);
            $table->string('error_code', 64)->nullable();
            $table->uuid('origin_workflow_run_id')->nullable();
            $table->unsignedSmallInteger('depth')->default(0);
            $table->timestamps();

            $table->unique(['workflow_id', 'trigger_type', 'trigger_reference'], 'workflow_runs_event_identity_unique');
            $table->index(['team_id', 'created_at']);
            $table->index(['workflow_id', 'created_at']);
        });

        Schema::table('workflow_runs', function (Blueprint $table) {
            $table->foreign('origin_workflow_run_id')
                ->references('public_id')
                ->on('workflow_runs')
                ->nullOnDelete();
        });

        Schema::create('workflow_run_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_action_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action_type', 64);
            $table->string('status', 32);
            $table->unsignedSmallInteger('position');
            $table->string('safe_summary', 500)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['workflow_run_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_run_actions');
        Schema::dropIfExists('workflow_runs');
        Schema::dropIfExists('workflow_actions');
        Schema::dropIfExists('workflow_conditions');
        Schema::dropIfExists('workflows');
    }
};
