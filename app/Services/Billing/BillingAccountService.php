<?php

namespace App\Services\Billing;

use App\Models\BillingAccount;
use App\Models\User;

final class BillingAccountService
{
    public function forUser(User $user): BillingAccount
    {
        return $user->billingAccount()->firstOrCreate([]);
    }

    public function lockedForUser(User $user): BillingAccount
    {
        $lockedUser = User::query()
            ->whereKey($user->getKey())
            ->lockForUpdate()
            ->firstOrFail();
        $account = $lockedUser->billingAccount()->firstOrCreate([]);

        return BillingAccount::query()
            ->whereKey($account->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function hasConsumedFreeWorkspace(BillingAccount $account): bool
    {
        return $account->free_workspace_consumed_at !== null;
    }

    public function canCreateFreeTeam(BillingAccount $account): bool
    {
        return ! $this->hasConsumedFreeWorkspace($account);
    }

    public function consumeFreeWorkspace(BillingAccount $account): void
    {
        if ($this->hasConsumedFreeWorkspace($account)) {
            return;
        }

        $account->forceFill(['free_workspace_consumed_at' => now()])->save();
    }
}
