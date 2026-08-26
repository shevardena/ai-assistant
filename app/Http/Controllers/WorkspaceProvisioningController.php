<?php

namespace App\Http\Controllers;

use App\Exceptions\BillingProviderException;
use App\Http\Requests\StartWorkspaceProvisioningRequest;
use App\Models\User;
use App\Models\WorkspaceProvisioning;
use App\Services\Billing\WorkspaceProvisioningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceProvisioningController extends Controller
{
    public function store(StartWorkspaceProvisioningRequest $request, WorkspaceProvisioningService $provisioning): RedirectResponse
    {
        $plan = $provisioning->plan((string) $request->validated('plan_key'));
        $workspace = WorkspaceProvisioning::query()->create([
            'user_id' => $request->user()->getKey(),
            'team_name' => $request->validated('name'),
            'plan_key' => $plan->key,
            'status' => 'pending',
            'expires_at' => now()->addHour(),
        ]);

        try {
            return redirect()->away($provisioning->checkout(
                $workspace,
                route('workspace-provisioning.success', $workspace),
                route('workspace-provisioning.cancelled', $workspace),
            ));
        } catch (BillingProviderException $exception) {
            Log::warning('Paid workspace provisioning checkout failed.', ['provisioning_id' => $workspace->getKey(), 'error_code' => $exception->errorCode]);

            return back()->withErrors(['billing' => 'Billing is temporarily unavailable. Please try again later.']);
        }
    }

    public function show(Request $request, WorkspaceProvisioning $workspaceProvisioning, WorkspaceProvisioningService $provisioning): Response
    {
        return $this->renderStatus($request->user(), $workspaceProvisioning, $provisioning);
    }

    public function success(Request $request, WorkspaceProvisioning $workspaceProvisioning, WorkspaceProvisioningService $provisioning): Response
    {
        return $this->renderStatus($request->user(), $workspaceProvisioning, $provisioning);
    }

    public function cancelled(Request $request, WorkspaceProvisioning $workspaceProvisioning, WorkspaceProvisioningService $provisioning): Response
    {
        $workspaceProvisioning = $this->owned($request->user(), $workspaceProvisioning);
        $provisioning->cancel($workspaceProvisioning);

        return $this->renderStatus($request->user(), $workspaceProvisioning->fresh(), $provisioning, 'cancelled');
    }

    public function retry(Request $request, WorkspaceProvisioning $workspaceProvisioning, WorkspaceProvisioningService $provisioning): RedirectResponse
    {
        $workspaceProvisioning = $this->owned($request->user(), $workspaceProvisioning);

        try {
            return redirect()->away($provisioning->retry($workspaceProvisioning, route('workspace-provisioning.success', $workspaceProvisioning), route('workspace-provisioning.cancelled', $workspaceProvisioning)));
        } catch (BillingProviderException $exception) {
            return back()->withErrors(['billing' => 'Billing is temporarily unavailable. Please try again later.']);
        }
    }

    private function renderStatus(User $user, WorkspaceProvisioning $workspaceProvisioning, WorkspaceProvisioningService $provisioning, ?string $notice = null): Response
    {
        $workspaceProvisioning = $this->owned($user, $workspaceProvisioning);
        $workspaceProvisioning = $provisioning->markExpired($workspaceProvisioning);
        $plan = $provisioning->plan($workspaceProvisioning->plan_key);

        return Inertia::render('billing/provisioning', [
            'provisioning' => [
                'id' => $workspaceProvisioning->getKey(),
                'team_name' => $workspaceProvisioning->team_name,
                'plan_key' => $plan->key,
                'plan_name' => $plan->name,
                'status' => $workspaceProvisioning->status->value,
                'expires_at' => $workspaceProvisioning->expires_at?->toIso8601String(),
                'team' => $workspaceProvisioning->team ? ['name' => $workspaceProvisioning->team->name, 'slug' => $workspaceProvisioning->team->slug] : null,
            ],
            'notice' => $notice,
        ]);
    }

    private function owned(User $user, WorkspaceProvisioning $workspaceProvisioning): WorkspaceProvisioning
    {
        return WorkspaceProvisioning::query()->whereKey($workspaceProvisioning->getKey())->where('user_id', $user->getKey())->firstOrFail();
    }
}
