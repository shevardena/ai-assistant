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
        Schema::create('team_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('plan_key', 40);
            $table->string('status', 20)->default('active');
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamps();
        });

        $now = now();
        $start = $now->copy()->startOfMonth();
        $end = $start->copy()->addMonth();

        DB::table('teams')->select('id')->orderBy('id')->chunkById(500, function ($teams) use ($now, $start, $end): void {
            DB::table('team_subscriptions')->insertOrIgnore($teams->map(fn (object $team): array => [
                'team_id' => $team->id,
                'plan_key' => 'legacy',
                'status' => 'active',
                'current_period_start' => $start,
                'current_period_end' => $end,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('team_subscriptions');
    }
};
