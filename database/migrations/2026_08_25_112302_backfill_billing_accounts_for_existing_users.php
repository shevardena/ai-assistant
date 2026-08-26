<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('users')
            ->select('id')
            ->orderBy('id')
            ->chunkById(500, function ($users): void {
                $userIds = $users->pluck('id')->all();
                $consumedAt = now();

                DB::table('billing_accounts')->insertOrIgnore(
                    collect($userIds)->map(fn (int $userId): array => [
                        'user_id' => $userId,
                        'created_at' => $consumedAt,
                        'updated_at' => $consumedAt,
                    ])->all(),
                );

                $ownersOfFreeTeams = DB::table('team_members')
                    ->join('team_subscriptions', 'team_subscriptions.team_id', '=', 'team_members.team_id')
                    ->whereIn('team_members.user_id', $userIds)
                    ->where('team_members.role', 'owner')
                    ->where('team_subscriptions.plan_key', 'free')
                    ->pluck('team_members.user_id')
                    ->all();

                if ($ownersOfFreeTeams === []) {
                    return;
                }

                DB::table('billing_accounts')
                    ->whereIn('user_id', $ownersOfFreeTeams)
                    ->whereNull('free_workspace_consumed_at')
                    ->update([
                        'free_workspace_consumed_at' => $consumedAt,
                        'updated_at' => $consumedAt,
                    ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This data backfill is reversed when the billing_accounts table is dropped.
    }
};
