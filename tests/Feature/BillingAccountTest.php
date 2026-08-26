<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Models\BillingAccount;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\TeamSubscription;
use App\Models\User;
use App\Services\Billing\BillingAccountService;
use Illuminate\Support\Facades\DB;

test('a user receives one lazy billing account and consumes the first free workspace', function () {
    $user = User::factory()->create();
    $service = app(BillingAccountService::class);

    $account = $service->forUser($user);
    $sameAccount = $service->forUser($user);

    expect($account->getKey())->toBe($sameAccount->getKey())
        ->and(BillingAccount::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and($service->hasConsumedFreeWorkspace($account))->toBeFalse();

    $team = app(CreateTeam::class)->handle($user, 'First Free Workspace');

    expect($team->subscription()->value('plan_key'))->toBe('free')
        ->and($team->pipelines()->where('is_default', true)->with('stages')->first()?->stages)->toHaveCount(6)
        ->and($service->hasConsumedFreeWorkspace($account->fresh()))->toBeTrue();
});

test('a second free workspace is rejected server side', function () {
    $user = User::factory()->create();

    app(CreateTeam::class)->handle($user, 'First Free Workspace');

    $response = $this
        ->actingAs($user)
        ->post(route('teams.store'), ['name' => 'Second Free Workspace']);

    $response
        ->assertRedirect()
        ->assertSessionHasErrors([
            'billing' => 'Your Free workspace has already been used. Additional workspaces require a paid plan.',
        ]);

    expect(Team::query()->where('name', 'Second Free Workspace')->exists())->toBeFalse();
});

test('deleting a free workspace does not restore the free allowance', function () {
    $user = User::factory()->create();
    $team = app(CreateTeam::class)->handle($user, 'Workspace to Delete');

    $this
        ->actingAs($user)
        ->delete(route('teams.destroy', $team), ['name' => $team->name])
        ->assertRedirect();

    $this->assertSoftDeleted('teams', ['id' => $team->id]);

    $response = $this
        ->actingAs($user)
        ->post(route('teams.store'), ['name' => 'Replacement Workspace']);

    $response->assertSessionHasErrors('billing');
    expect(Team::query()->where('name', 'Replacement Workspace')->exists())->toBeFalse();
});

test('an invited user keeps their own free workspace allowance', function () {
    $owner = User::factory()->create();
    $invitee = User::factory()->create();
    $team = Team::factory()->create();

    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    TeamSubscription::factory()->create([
        'team_id' => $team->id,
        'plan_key' => 'business',
    ]);

    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => $invitee->email,
        'invited_by' => $owner->id,
    ]);

    $this
        ->actingAs($invitee)
        ->post(route('invitations.accept', $invitation))
        ->assertRedirect();

    expect($invitee->billingAccount()->exists())->toBeFalse();

    $personalWorkspace = app(CreateTeam::class)->handle($invitee, 'Invitee Free Workspace');

    expect($personalWorkspace->subscription()->value('plan_key'))->toBe('free');
});

test('paid-only users retain their first free workspace allowance', function () {
    $user = User::factory()->create();
    $paidTeam = Team::factory()->create(['name' => 'Existing Pro Workspace']);

    $paidTeam->members()->attach($user, ['role' => TeamRole::Owner->value]);
    TeamSubscription::factory()->create([
        'team_id' => $paidTeam->id,
        'plan_key' => 'pro',
    ]);

    $freeTeam = app(CreateTeam::class)->handle($user, 'First Free Workspace');

    expect($freeTeam->subscription()->value('plan_key'))->toBe('free')
        ->and($paidTeam->subscription()->value('plan_key'))->toBe('pro');
});

test('free and multiple paid teams retain independent team subscriptions', function () {
    $user = User::factory()->create();
    $freeTeam = app(CreateTeam::class)->handle($user, 'Free Workspace');
    $starterTeam = Team::factory()->create(['name' => 'Starter Workspace']);
    $businessTeam = Team::factory()->create(['name' => 'Business Workspace']);

    foreach ([$starterTeam, $businessTeam] as $paidTeam) {
        $paidTeam->members()->attach($user, ['role' => TeamRole::Owner->value]);
    }

    TeamSubscription::factory()->create([
        'team_id' => $starterTeam->id,
        'plan_key' => 'starter',
    ]);
    TeamSubscription::factory()->create([
        'team_id' => $businessTeam->id,
        'plan_key' => 'business',
    ]);

    expect($freeTeam->subscription()->value('plan_key'))->toBe('free')
        ->and($starterTeam->subscription()->value('plan_key'))->toBe('starter')
        ->and($businessTeam->subscription()->value('plan_key'))->toBe('business');
});

test('free allowance consumption rolls back with its transaction', function () {
    $user = User::factory()->create();
    $service = app(BillingAccountService::class);

    try {
        DB::transaction(function () use ($service, $user): void {
            $account = $service->lockedForUser($user);
            $service->consumeFreeWorkspace($account);

            throw new RuntimeException('Simulated workspace creation failure.');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Simulated workspace creation failure.');
    }

    expect($service->forUser($user)->fresh()->free_workspace_consumed_at)->toBeNull();
});

test('free allowance consumption is isolated between users', function () {
    $consumedUser = User::factory()->create();
    $eligibleUser = User::factory()->create();

    app(CreateTeam::class)->handle($consumedUser, 'Consumed User Workspace');
    $team = app(CreateTeam::class)->handle($eligibleUser, 'Eligible User Workspace');

    expect($team->subscription()->value('plan_key'))->toBe('free')
        ->and($consumedUser->billingAccount()->value('free_workspace_consumed_at'))->not->toBeNull()
        ->and($eligibleUser->billingAccount()->value('free_workspace_consumed_at'))->not->toBeNull();
});

test('example', function () {
    $response = $this->get('/');

    $response->assertStatus(200);
});
