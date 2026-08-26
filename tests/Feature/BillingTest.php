<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\PlanFeature;
use App\Enums\PlanLimit;
use App\Enums\RuntimeMode;
use App\Enums\SubscriptionStatus;
use App\Enums\TeamRole;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\TeamSubscription;
use App\Models\ToolRun;
use App\Models\User;
use App\Models\WidgetVisitor;
use App\Services\Billing\TeamEntitlementService;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function billingContext(TeamRole $role = TeamRole::Owner, string $plan = 'legacy'): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);
    $user->switchTeam($team);
    TeamSubscription::factory()->create([
        'team_id' => $team->id,
        'plan_key' => $plan,
    ]);

    return [$user, $team];
}

test('billing page is scoped to the selected team and aggregates safe usage data', function () {
    [$user, $team] = billingContext(TeamRole::Owner, 'pro');
    $bot = Bot::factory()->create(['team_id' => $team->id]);
    $visitor = WidgetVisitor::factory()->create(['bot_id' => $bot->id]);

    Conversation::factory()->create([
        'bot_id' => $bot->id,
        'visitor_id' => $visitor->id,
        'metadata' => ['source' => 'widget'],
    ]);
    Conversation::factory()->create([
        'bot_id' => $bot->id,
        'metadata' => ['source' => 'dashboard_preview'],
    ]);
    ToolRun::factory()->create([
        'team_id' => $team->id,
        'bot_id' => $bot->id,
        'runtime_mode' => RuntimeMode::Normal->value,
    ]);
    ToolRun::factory()->create([
        'team_id' => $team->id,
        'bot_id' => $bot->id,
        'runtime_mode' => RuntimeMode::Preview->value,
    ]);
    ToolRun::factory()->create([
        'team_id' => $team->id,
        'bot_id' => $bot->id,
        'runtime_mode' => RuntimeMode::Test->value,
    ]);

    $this->actingAs($user)
        ->get(route('billing.index', $team->slug))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('billing/index')
            ->where('summary.plan.key', 'pro')
            ->where('summary.usage.bots.used', 1)
            ->where('summary.usage.monthly_conversations.used', 1)
            ->where('summary.usage.monthly_actions.used', 1)
            ->has('plans', 4),
        );
});

test('a missing subscription safely resolves to unrestricted legacy access', function () {
    [$user, $team] = billingContext();
    $team->subscription()->delete();

    $entitlements = app(TeamEntitlementService::class);

    expect($entitlements->currentPlan($team)->key)->toBe('legacy')
        ->and($entitlements->hasFeature($team, PlanFeature::VoiceInput))->toBeTrue()
        ->and($entitlements->isUnlimited($team, PlanLimit::Bots))->toBeTrue()
        ->and($entitlements->isUnlimited($team, PlanLimit::MonthlyConversations))->toBeTrue();
});

test('an expired canceled paid subscription falls back to free voice access', function (): void {
    [, $team] = billingContext(TeamRole::Owner, 'starter');
    $team->subscription()->update([
        'provider' => 'stripe',
        'status' => SubscriptionStatus::Cancelled->value,
        'current_period_end' => now()->subDay(),
    ]);

    expect(app(TeamEntitlementService::class)->hasFeature($team, PlanFeature::VoiceInput))->toBeFalse();
});

test('new teams receive an explicit free subscription', function () {
    $user = User::factory()->create();

    $team = app(CreateTeam::class)->handle($user, 'New Billing Team');

    expect($team->subscription()->value('plan_key'))->toBe('free')
        ->and($user->current_team_id)->toBe($team->id);
});

test('team billing permissions follow the owner and admin mapping', function () {
    [$owner, $team] = billingContext(TeamRole::Owner);

    $this->actingAs($owner)
        ->get(route('billing.index', $team->slug))
        ->assertOk();

    $admin = User::factory()->create();
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value]);

    $this->actingAs($admin)
        ->get(route('billing.index', $team->slug))
        ->assertOk();

    $support = User::factory()->create();
    $team->members()->attach($support, ['role' => TeamRole::SupportAgent->value]);

    $this->actingAs($support)
        ->get(route('billing.index', $team->slug))
        ->assertForbidden();
});

test('a user cannot view another teams billing subscription', function () {
    [$user, $team] = billingContext(TeamRole::Owner, 'free');
    $otherTeam = Team::factory()->create();
    TeamSubscription::factory()->create(['team_id' => $otherTeam->id, 'plan_key' => 'business']);

    $this->actingAs($user)
        ->get(route('billing.index', $otherTeam->slug))
        ->assertForbidden();
});

test('bot creation and template onboarding share the same bot limit', function () {
    [$user, $team] = billingContext(TeamRole::Owner, 'free');
    $team->subscription()->update(['plan_key' => 'free']);
    Bot::factory()->count(2)->create(['team_id' => $team->id]);

    $this->actingAs($user)
        ->from(route('bots.create', $team->slug))
        ->post(route('bots.store', $team->slug), [
            'name' => 'Blocked Bot',
            'slug' => 'blocked-bot',
            'default_language' => 'en',
        ])
        ->assertSessionHasErrors('name');

    $this->actingAs($user)
        ->from(route('onboarding.index', $team->slug))
        ->post(route('onboarding.apply', $team->slug), [
            'template_key' => 'ecommerce',
            'bot_name' => 'Blocked Template Bot',
        ])
        ->assertSessionHasErrors('botName');

    expect($team->bots()->count())->toBe(2);
});

test('legacy teams retain unrestricted bot creation', function () {
    [$user, $team] = billingContext(TeamRole::Owner, 'legacy');
    Bot::factory()->count(20)->create(['team_id' => $team->id]);

    $this->actingAs($user)
        ->post(route('bots.store', $team->slug), [
            'name' => 'Additional Bot',
            'slug' => 'additional-bot',
            'default_language' => 'en',
        ])
        ->assertRedirect();
});

test('production widget conversations stop at a hard quota without exposing billing details', function () {
    [$user, $team] = billingContext(TeamRole::Owner, 'free');
    $team->subscription()->update(['plan_key' => 'free']);
    $bot = Bot::factory()->create(['team_id' => $team->id, 'status' => 'published']);
    $bot->domains()->create(['domain' => 'example.com']);
    $visitor = WidgetVisitor::factory()->create(['bot_id' => $bot->id]);
    Conversation::factory()->create([
        'bot_id' => $bot->id,
        'visitor_id' => $visitor->id,
        'metadata' => ['source' => 'widget'],
    ]);
    Conversation::factory()->count(249)->create([
        'bot_id' => $bot->id,
        'metadata' => ['source' => 'widget'],
    ]);

    $this->withHeader('Origin', 'https://example.com')
        ->postJson(route('widget.session', $bot->public_id), [
            'visitor_id' => $visitor->public_id,
            'new_conversation' => true,
        ])
        ->assertStatus(503)
        ->assertJsonPath('message', 'This assistant is temporarily unavailable.')
        ->assertJsonMissing(['plan_key' => 'free']);

    expect($team->conversations()->where('metadata->source', 'widget')->count())->toBe(250);
});

test('preview and test ToolRuns are excluded from monthly action usage', function () {
    [$user, $team] = billingContext();
    $bot = Bot::factory()->create(['team_id' => $team->id]);

    foreach ([RuntimeMode::Normal, RuntimeMode::Preview, RuntimeMode::Test] as $mode) {
        ToolRun::factory()->create([
            'team_id' => $team->id,
            'bot_id' => $bot->id,
            'runtime_mode' => $mode->value,
            'action_reference' => (string) Str::uuid(),
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    expect(app(TeamEntitlementService::class)->usage($team, PlanLimit::MonthlyActions))->toBe(1);
});

test('active team members are checked against the member limit when an invitation is accepted', function () {
    [$owner, $team] = billingContext(TeamRole::Owner, 'free');
    $team->members()->attach(User::factory()->create(), ['role' => TeamRole::Member->value]);
    $team->members()->attach(User::factory()->create(), ['role' => TeamRole::Member->value]);
    $invitee = User::factory()->create();
    $invitation = TeamInvitation::factory()->create([
        'team_id' => $team->id,
        'email' => $invitee->email,
        'invited_by' => $owner->id,
        'role' => TeamRole::Member->value,
    ]);

    $this->actingAs($invitee)
        ->from(route('dashboard'))
        ->post(route('invitations.accept', $invitation))
        ->assertSessionHasErrors('email');

    expect($team->memberships()->where('user_id', $invitee->id)->exists())->toBeFalse();
});
