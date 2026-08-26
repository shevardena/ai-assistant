<?php

use App\Enums\SubscriptionStatus;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamSubscription;
use App\Models\User;
use App\Models\WorkspaceProvisioning;
use Illuminate\Support\Facades\Http;

function stripeBillingContext(TeamRole $role = TeamRole::Owner, string $plan = 'free'): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);
    $user->switchTeam($team);
    $subscription = TeamSubscription::factory()->create(['team_id' => $team->id, 'plan_key' => $plan]);

    return [$user, $team, $subscription];
}

function signedStripePayload(array $event): array
{
    $payload = json_encode($event, JSON_THROW_ON_ERROR);
    $timestamp = (string) time();
    $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test');

    return [$payload, 't='.$timestamp.',v1='.$signature];
}

beforeEach(function (): void {
    config([
        'services.stripe.secret' => 'sk_test',
        'services.stripe.webhook_secret' => 'whsec_test',
        'services.stripe.api_url' => 'https://stripe.test/v1',
        'billing.plans.starter.stripe_price_id' => 'price_starter',
        'billing.plans.pro.stripe_price_id' => 'price_pro',
        'billing.plans.business.stripe_price_id' => 'price_business',
    ]);
});

test('owner checkout uses the trusted price and one Team customer', function () {
    [$user, $team, $subscription] = stripeBillingContext();
    Http::fake([
        'https://stripe.test/v1/customers' => Http::response(['id' => 'cus_team'], 200),
        'https://stripe.test/v1/checkout/sessions' => Http::response(['id' => 'cs_test', 'url' => 'https://checkout.stripe.test/cs_test'], 200),
    ]);

    $this->actingAs($user)
        ->post(route('billing.checkout', $team->slug), ['plan_key' => 'pro', 'price_id' => 'price_attacker'])
        ->assertRedirect('https://checkout.stripe.test/cs_test');

    expect($subscription->fresh()->provider_customer_id)->toBe('cus_team');
    Http::assertSent(fn ($request): bool => $request->url() === 'https://stripe.test/v1/checkout/sessions'
        && $request['line_items[0][price]'] === 'price_pro');
});

test('admins can view billing but cannot start checkout', function () {
    [$user, $team] = stripeBillingContext(TeamRole::Admin);

    $this->actingAs($user)
        ->get(route('billing.index', $team->slug))
        ->assertOk();

    $this->actingAs($user)
        ->post(route('billing.checkout', $team->slug), ['plan_key' => 'pro'])
        ->assertForbidden();
});

test('a Team reuses its Stripe customer and portal return URL is server controlled', function () {
    [$user, $team, $subscription] = stripeBillingContext();
    Http::fake([
        'https://stripe.test/v1/customers' => Http::response(['id' => 'cus_team'], 200),
        'https://stripe.test/v1/checkout/sessions' => Http::response(['id' => 'cs_test', 'url' => 'https://checkout.stripe.test/cs_test'], 200),
        'https://stripe.test/v1/billing_portal/sessions' => Http::response(['url' => 'https://billing.stripe.test/session'], 200),
    ]);

    $this->actingAs($user)->post(route('billing.checkout', $team->slug), ['plan_key' => 'pro'])->assertRedirect();
    $this->actingAs($user)->post(route('billing.portal', $team->slug), ['return_url' => 'https://attacker.test'])->assertRedirect('https://billing.stripe.test/session');

    expect(collect(Http::recorded())->filter(fn ($pair): bool => str_ends_with($pair[0]->url(), '/customers'))->count())->toBe(1)
        ->and($subscription->fresh()->provider_customer_id)->toBe('cus_team');
    Http::assertSent(fn ($request): bool => $request->url() === 'https://stripe.test/v1/billing_portal/sessions'
        && $request['customer'] === 'cus_team'
        && $request['return_url'] === route('billing.index', $team->slug));
});

test('signed active subscription webhook projects the paid plan and period', function () {
    [$user, $team, $subscription] = stripeBillingContext();
    $event = [
        'id' => 'evt_active',
        'type' => 'customer.subscription.updated',
        'data' => ['object' => [
            'id' => 'sub_test',
            'customer' => 'cus_team',
            'status' => 'active',
            'cancel_at_period_end' => false,
            'current_period_start' => 1787486400,
            'current_period_end' => 1790164800,
            'items' => ['data' => [['id' => 'si_test', 'price' => ['id' => 'price_pro']]]],
        ]],
    ];
    $subscription->update(['provider' => 'stripe', 'provider_customer_id' => 'cus_team']);
    [$payload, $signature] = signedStripePayload($event);

    $this->call('POST', route('stripe.webhook'), [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $payload)
        ->assertOk();

    expect($subscription->fresh()->plan_key)->toBe('pro')
        ->and($subscription->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->fresh()->provider_subscription_id)->toBe('sub_test');
});

test('invalid or duplicate Stripe webhooks cannot change billing state twice', function () {
    [$user, $team, $subscription] = stripeBillingContext();
    $event = [
        'id' => 'evt_duplicate',
        'type' => 'customer.subscription.updated',
        'data' => ['object' => [
            'id' => 'sub_test', 'customer' => 'cus_team', 'status' => 'active', 'cancel_at_period_end' => false,
            'items' => ['data' => [['id' => 'si_test', 'price' => ['id' => 'price_pro']]]],
        ]],
    ];
    $subscription->update(['provider' => 'stripe', 'provider_customer_id' => 'cus_team']);
    [$payload, $signature] = signedStripePayload($event);

    $this->post(route('stripe.webhook'), [], ['Stripe-Signature' => 't=1,v1=invalid'])
        ->assertStatus(400);
    $this->call('POST', route('stripe.webhook'), [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $payload)->assertOk();
    $this->call('POST', route('stripe.webhook'), [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $payload)->assertOk();

    expect($subscription->fresh()->plan_key)->toBe('pro')
        ->and($subscription->fresh()->provider_subscription_id)->toBe('sub_test');
});

test('unknown Stripe prices fail closed and do not grant a paid plan', function () {
    [$user, $team, $subscription] = stripeBillingContext();
    $event = [
        'id' => 'evt_unknown_price', 'type' => 'customer.subscription.updated',
        'data' => ['object' => [
            'id' => 'sub_unknown', 'customer' => 'cus_team', 'status' => 'active', 'cancel_at_period_end' => false,
            'items' => ['data' => [['id' => 'si_test', 'price' => ['id' => 'price_unknown']]]],
        ]],
    ];
    $subscription->update(['provider' => 'stripe', 'provider_customer_id' => 'cus_team']);
    [$payload, $signature] = signedStripePayload($event);

    $this->call('POST', route('stripe.webhook'), [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $payload)->assertOk();

    expect($subscription->fresh()->plan_key)->toBe('free');
});

test('payment failure keeps the plan during grace and ended subscriptions fall back to free', function () {
    [$user, $team, $subscription] = stripeBillingContext(plan: 'pro');
    $subscription->update(['provider' => 'stripe', 'provider_customer_id' => 'cus_team', 'provider_subscription_id' => 'sub_test']);
    $failed = [
        'id' => 'evt_failed', 'type' => 'invoice.payment_failed',
        'data' => ['object' => ['id' => 'in_test', 'customer' => 'cus_team', 'subscription' => 'sub_test']],
    ];
    [$payload, $signature] = signedStripePayload($failed);
    $this->call('POST', route('stripe.webhook'), [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $payload)->assertOk();

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::PastDue)
        ->and($subscription->fresh()->plan_key)->toBe('pro');

    $ended = [
        'id' => 'evt_ended', 'type' => 'customer.subscription.deleted',
        'data' => ['object' => ['id' => 'sub_test', 'customer' => 'cus_team']],
    ];
    [$payload, $signature] = signedStripePayload($ended);
    $this->call('POST', route('stripe.webhook'), [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $payload)->assertOk();

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($subscription->fresh()->plan_key)->toBe('free');
});

test('paid workspace checkout stays pending until a verified completed session webhook', function () {
    $user = User::factory()->create();
    Http::fake([
        'https://stripe.test/v1/customers' => Http::response(['id' => 'cus_workspace'], 200),
        'https://stripe.test/v1/checkout/sessions' => Http::response(['id' => 'cs_workspace', 'url' => 'https://checkout.stripe.test/cs_workspace'], 200),
        'https://stripe.test/v1/subscriptions/sub_workspace' => Http::response([
            'id' => 'sub_workspace', 'customer' => 'cus_workspace', 'status' => 'active',
            'items' => ['data' => [['id' => 'si_workspace', 'price' => ['id' => 'price_pro']]]],
        ], 200),
    ]);

    $this->actingAs($user)
        ->post(route('workspace-provisioning.store'), ['name' => 'Paid Workspace', 'plan_key' => 'pro'])
        ->assertRedirect('https://checkout.stripe.test/cs_workspace');

    $provisioning = WorkspaceProvisioning::query()->where('user_id', $user->id)->firstOrFail();
    expect($provisioning->status->value)->toBe('checkout_created')
        ->and($user->ownedTeams()->where('is_personal', false)->exists())->toBeFalse();

    $event = [
        'id' => 'evt_workspace_completed', 'type' => 'checkout.session.completed',
        'data' => ['object' => [
            'id' => 'cs_workspace', 'mode' => 'subscription', 'status' => 'complete', 'payment_status' => 'paid',
            'customer' => 'cus_workspace', 'subscription' => 'sub_workspace',
            'metadata' => ['workspace_provisioning_id' => (string) $provisioning->id, 'plan_key' => 'pro'],
        ]],
    ];
    [$payload, $signature] = signedStripePayload($event);

    $this->call('POST', route('stripe.webhook'), [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $payload)->assertOk();

    expect($provisioning->fresh()->status->value)->toBe('completed')
        ->and($provisioning->fresh()->team_id)->not->toBeNull()
        ->and($user->ownedTeams()->where('is_personal', false)->count())->toBe(1)
        ->and($provisioning->fresh()->team->subscription->plan_key)->toBe('pro')
        ->and($provisioning->fresh()->team->pipelines()->where('is_default', true)->with('stages')->first()?->stages)->toHaveCount(6);

    $this->call('POST', route('stripe.webhook'), [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $payload)->assertOk();
    expect($user->ownedTeams()->where('is_personal', false)->count())->toBe(1);
});

test('subscription events can arrive before checkout completion without activating a Team', function () {
    $user = User::factory()->create();
    Http::fake([
        'https://stripe.test/v1/customers' => Http::response(['id' => 'cus_ordered'], 200),
        'https://stripe.test/v1/checkout/sessions' => Http::response(['id' => 'cs_ordered', 'url' => 'https://checkout.stripe.test/cs_ordered'], 200),
        'https://stripe.test/v1/subscriptions/sub_ordered' => Http::response([
            'id' => 'sub_ordered', 'customer' => 'cus_ordered', 'status' => 'active',
            'items' => ['data' => [['id' => 'si_ordered', 'price' => ['id' => 'price_pro']]]],
        ], 200),
    ]);

    $this->actingAs($user)->post(route('workspace-provisioning.store'), ['name' => 'Ordered Workspace', 'plan_key' => 'pro'])->assertRedirect();
    $provisioning = WorkspaceProvisioning::query()->where('user_id', $user->id)->firstOrFail();
    $subscriptionEvent = [
        'id' => 'evt_subscription_first', 'type' => 'customer.subscription.created',
        'data' => ['object' => [
            'id' => 'sub_ordered', 'customer' => 'cus_ordered', 'status' => 'active',
            'metadata' => ['workspace_provisioning_id' => (string) $provisioning->id, 'plan_key' => 'pro'],
            'items' => ['data' => [['id' => 'si_ordered', 'price' => ['id' => 'price_pro']]]],
        ]],
    ];
    [$payload, $signature] = signedStripePayload($subscriptionEvent);
    $this->call('POST', route('stripe.webhook'), [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $payload)->assertOk();

    expect($provisioning->fresh()->provider_subscription_id)->toBe('sub_ordered')
        ->and($user->ownedTeams()->where('is_personal', false)->exists())->toBeFalse();

    $completedEvent = [
        'id' => 'evt_ordered_completed', 'type' => 'checkout.session.completed',
        'data' => ['object' => [
            'id' => 'cs_ordered', 'mode' => 'subscription', 'status' => 'complete', 'payment_status' => 'paid',
            'customer' => 'cus_ordered', 'subscription' => 'sub_ordered',
            'metadata' => ['workspace_provisioning_id' => (string) $provisioning->id, 'plan_key' => 'pro'],
        ]],
    ];
    [$payload, $signature] = signedStripePayload($completedEvent);
    $this->call('POST', route('stripe.webhook'), [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $payload)->assertOk();

    expect($provisioning->fresh()->status->value)->toBe('completed')
        ->and($user->ownedTeams()->where('is_personal', false)->count())->toBe(1);
});

test('cancelling paid checkout leaves no usable Team and does not consume Free eligibility', function () {
    $user = User::factory()->create();
    Http::fake([
        'https://stripe.test/v1/customers' => Http::response(['id' => 'cus_cancelled'], 200),
        'https://stripe.test/v1/checkout/sessions' => Http::response(['id' => 'cs_cancelled', 'url' => 'https://checkout.stripe.test/cs_cancelled'], 200),
    ]);

    $this->actingAs($user)->post(route('workspace-provisioning.store'), ['name' => 'Cancelled Workspace', 'plan_key' => 'pro'])->assertRedirect();
    $provisioning = WorkspaceProvisioning::query()->where('user_id', $user->id)->firstOrFail();
    $this->actingAs($user)->get(route('workspace-provisioning.cancelled', $provisioning))->assertOk();

    expect($provisioning->fresh()->status->value)->toBe('cancelled')
        ->and($user->ownedTeams()->where('is_personal', false)->exists())->toBeFalse()
        ->and($user->billingAccount->free_workspace_consumed_at)->toBeNull();
});

test('paid checkout is rejected for a Team that already has a paid Stripe subscription', function () {
    [$user, $team, $subscription] = stripeBillingContext(plan: 'pro');
    $subscription->update(['provider' => 'stripe', 'provider_subscription_id' => 'sub_existing']);

    $this->actingAs($user)
        ->post(route('billing.checkout', $team->slug), ['plan_key' => 'business'])
        ->assertSessionHasErrors('plan_key');
});

test('workspace provisioning status is visible only to its authenticated owner', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $provisioning = WorkspaceProvisioning::factory()->create(['user_id' => $owner->id]);

    $this->actingAs($otherUser)
        ->get(route('workspace-provisioning.show', $provisioning))
        ->assertNotFound();

    $this->actingAs($owner)
        ->get(route('workspace-provisioning.show', $provisioning))
        ->assertOk();
});
