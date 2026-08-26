<?php

use App\Enums\TeamRole;
use App\Models\Appointment;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerActivity;
use App\Models\CustomerIdentity;
use App\Models\CustomerNote;
use App\Models\Lead;
use App\Models\SupportTicket;
use App\Models\Team;
use App\Models\ToolRun;
use App\Models\User;
use App\Services\Customers\CustomerCustomFieldService;
use App\Services\Customers\CustomerFactService;
use App\Services\Customers\CustomerIdentityResolutionService;
use App\Services\Customers\CustomerIdentityService;
use App\Services\Customers\CustomerMergeService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

function mergeHardeningContext(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $user->switchTeam($team);

    return [$user, $team];
}

function mergeHardeningPair(Team $team): array
{
    return [
        Customer::factory()->create(['team_id' => $team->id, 'email' => 'source@example.com']),
        Customer::factory()->create(['team_id' => $team->id, 'email' => 'destination@example.com']),
    ];
}

test('merge preview is non-mutating and reports destination-wins conflicts', function (): void {
    [, $team] = mergeHardeningContext();
    [$source, $destination] = mergeHardeningPair($team);
    $field = app(CustomerCustomFieldService::class)->create($team, ['key' => 'plan', 'label' => 'Plan', 'type' => 'text']);
    app(CustomerCustomFieldService::class)->saveValues($team, $source, ['plan' => 'pro']);
    app(CustomerCustomFieldService::class)->saveValues($team, $destination, ['plan' => 'enterprise']);
    app(CustomerFactService::class)->save($team, $source, ['key' => 'region', 'value' => 'West']);
    app(CustomerFactService::class)->save($team, $destination, ['key' => 'region', 'value' => 'East']);
    $sourceIdentityIds = $source->identities()->pluck('id')->all();
    $destinationTags = $destination->tags()->pluck('customer_tags.id')->all();

    $preview = app(CustomerMergeService::class)->preview($team, $source, $destination);

    expect($preview['blocked'])->toBeFalse()
        ->and($preview['conflicts']['customFields'])->toHaveCount(1)
        ->and($preview['conflicts']['facts'])->toHaveCount(1)
        ->and($source->fresh()->merged_into_customer_id)->toBeNull()
        ->and($source->fresh()->identities()->pluck('id')->all())->toBe($sourceIdentityIds)
        ->and($destination->fresh()->tags()->pluck('customer_tags.id')->all())->toBe($destinationTags);
});

test('successful merge preserves linked ids, unions tags, and applies source-only value rules', function (): void {
    [$user, $team] = mergeHardeningContext();
    [$source, $destination] = mergeHardeningPair($team);
    $bot = Bot::factory()->create(['team_id' => $team->id]);
    $toolRun = ToolRun::factory()->create(['team_id' => $team->id, 'bot_id' => $bot->id]);
    $conversation = Conversation::factory()->create(['bot_id' => $bot->id, 'customer_id' => $source->id]);
    $lead = Lead::factory()->create(['team_id' => $team->id, 'bot_id' => $bot->id, 'tool_run_id' => $toolRun->id, 'customer_id' => $source->id]);
    $appointment = Appointment::factory()->create(['team_id' => $team->id, 'bot_id' => $bot->id, 'tool_run_id' => $toolRun->id, 'customer_id' => $source->id]);
    $ticket = SupportTicket::factory()->create(['team_id' => $team->id, 'bot_id' => $bot->id, 'tool_run_id' => $toolRun->id, 'customer_id' => $source->id]);
    $note = CustomerNote::factory()->create(['team_id' => $team->id, 'customer_id' => $source->id, 'user_id' => $user->id]);
    $sourceTag = $team->customerTags()->create(['name' => 'Source tag', 'slug' => 'source-tag']);
    $destinationTag = $team->customerTags()->create(['name' => 'Destination tag', 'slug' => 'destination-tag']);
    $source->tags()->attach($sourceTag);
    $destination->tags()->attach($destinationTag);
    $field = app(CustomerCustomFieldService::class)->create($team, ['key' => 'source_only', 'label' => 'Source only', 'type' => 'text']);
    app(CustomerCustomFieldService::class)->saveValues($team, $source, [$field->key => 'keep me']);
    app(CustomerFactService::class)->save($team, $source, ['key' => 'source_only', 'value' => 'keep me']);
    $identityIds = $source->identities()->pluck('id')->all();

    app(CustomerMergeService::class)->merge($team, $source, $destination, $user);

    expect($conversation->fresh()->customer_id)->toBe($destination->id)
        ->and($lead->fresh()->customer_id)->toBe($destination->id)
        ->and($appointment->fresh()->customer_id)->toBe($destination->id)
        ->and($ticket->fresh()->customer_id)->toBe($destination->id)
        ->and($note->fresh()->customer_id)->toBe($destination->id)
        ->and($conversation->id)->toBe($conversation->fresh()->id)
        ->and($lead->id)->toBe($lead->fresh()->id)
        ->and($appointment->id)->toBe($appointment->fresh()->id)
        ->and($ticket->id)->toBe($ticket->fresh()->id)
        ->and($destination->fresh()->tags()->pluck('customer_tags.id')->sort()->values()->all())->toBe(collect([$destinationTag->id, $sourceTag->id])->sort()->values()->all())
        ->and($destination->fresh()->customFieldValues()->where('customer_custom_field_id', $field->id)->value('value_text'))->toBe('keep me')
        ->and($destination->fresh()->facts()->where('key', 'source_only')->value('value'))->toBe('keep me')
        ->and(CustomerIdentity::query()->whereIn('id', $identityIds)->where('customer_id', $destination->id)->count())->toBe(count($identityIds));
});

test('merge rollback restores every reassigned relation when final activity recording fails', function (): void {
    [$user, $team] = mergeHardeningContext();
    [$source, $destination] = mergeHardeningPair($team);
    $bot = Bot::factory()->create(['team_id' => $team->id]);
    $toolRun = ToolRun::factory()->create(['team_id' => $team->id, 'bot_id' => $bot->id]);
    $conversation = Conversation::factory()->create(['bot_id' => $bot->id, 'customer_id' => $source->id]);
    $lead = Lead::factory()->create(['team_id' => $team->id, 'bot_id' => $bot->id, 'tool_run_id' => $toolRun->id, 'customer_id' => $source->id]);
    $appointment = Appointment::factory()->create(['team_id' => $team->id, 'bot_id' => $bot->id, 'tool_run_id' => $toolRun->id, 'customer_id' => $source->id]);
    $ticket = SupportTicket::factory()->create(['team_id' => $team->id, 'bot_id' => $bot->id, 'tool_run_id' => $toolRun->id, 'customer_id' => $source->id]);
    $source->notes()->create(['team_id' => $team->id, 'user_id' => $user->id, 'body' => 'Rollback note']);
    $tag = $team->customerTags()->create(['name' => 'Rollback', 'slug' => 'rollback']);
    $source->tags()->attach($tag);
    $field = app(CustomerCustomFieldService::class)->create($team, ['key' => 'rollback_field', 'label' => 'Rollback field', 'type' => 'text']);
    app(CustomerCustomFieldService::class)->saveValues($team, $source, [$field->key => 'rollback value']);
    app(CustomerFactService::class)->save($team, $source, ['key' => 'rollback_fact', 'value' => 'rollback value']);
    $sourceIdentityIds = $source->identities()->pluck('id')->all();
    $dispatcher = CustomerActivity::getEventDispatcher();
    CustomerActivity::creating(static function (): void {
        throw new RuntimeException('forced merge failure');
    });

    try {
        expect(fn (): Customer => app(CustomerMergeService::class)->merge($team, $source, $destination, $user))->toThrow(RuntimeException::class);
    } finally {
        CustomerActivity::setEventDispatcher($dispatcher);
    }

    expect($source->fresh()->merged_into_customer_id)->toBeNull()
        ->and($source->fresh()->status->value)->toBe('new')
        ->and($conversation->fresh()->customer_id)->toBe($source->id)
        ->and($lead->fresh()->customer_id)->toBe($source->id)
        ->and($appointment->fresh()->customer_id)->toBe($source->id)
        ->and($ticket->fresh()->customer_id)->toBe($source->id)
        ->and($source->fresh()->notes()->where('body', 'Rollback note')->exists())->toBeTrue()
        ->and($source->fresh()->tags()->whereKey($tag->id)->exists())->toBeTrue()
        ->and($source->fresh()->customFieldValues()->where('customer_custom_field_id', $field->id)->value('value_text'))->toBe('rollback value')
        ->and($source->fresh()->facts()->where('key', 'rollback_fact')->exists())->toBeTrue()
        ->and(CustomerIdentity::query()->whereKey($sourceIdentityIds)->where('customer_id', $source->id)->count())->toBe(count($sourceIdentityIds));
});

test('merge guards reject self, cross-team, and already merged customers', function (): void {
    [, $team] = mergeHardeningContext();
    [$source, $destination] = mergeHardeningPair($team);
    $foreignTeam = Team::factory()->create();
    $foreign = Customer::factory()->create(['team_id' => $foreignTeam->id]);
    $service = app(CustomerMergeService::class);

    expect(fn (): array => $service->preview($team, $source, $source))->toThrow(ValidationException::class)
        ->and(fn (): array => $service->preview($team, $source, $foreign))->toThrow(ModelNotFoundException::class);

    $service->merge($team, $source, $destination);
    expect(fn (): Customer => $service->merge($team, $source, $destination))->toThrow(ValidationException::class)
        ->and(fn (): Customer => $service->merge($team, $destination, $source))->toThrow(ValidationException::class);
});

test('post-merge secondary identities resolve to the surviving customer', function (): void {
    [, $team] = mergeHardeningContext();
    [$source, $destination] = mergeHardeningPair($team);
    $identity = app(CustomerIdentityService::class)->add($team, $source, ['type' => 'email', 'value' => 'duplicate@example.com']);

    app(CustomerMergeService::class)->merge($team, $source, $destination);

    expect(app(CustomerIdentityResolutionService::class)->resolve($team, ['email' => $identity->value])->customer?->is($destination))->toBeTrue()
        ->and(Customer::query()->where('email', 'duplicate@example.com')->whereNull('merged_into_customer_id')->exists())->toBeFalse();
});
