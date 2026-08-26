<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\CustomerActivityType;
use App\Enums\DealStatus;
use App\Enums\PipelineStageSemanticType;
use App\Enums\TeamRole;
use App\Models\Bot;
use App\Models\Customer;
use App\Models\CustomerActivity;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Team;
use App\Models\ToolRun;
use App\Models\User;
use App\Services\Customers\CustomerMergeService;
use App\Services\Customers\CustomerService;
use App\Services\Deals\DealService;
use App\Services\Deals\PipelineService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

afterEach(function (): void {
    Carbon::setTestNow();
});

function hardeningSalesContext(): array
{
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $pipeline = app(PipelineService::class)->ensureDefault($team)['pipeline'];

    return [
        'user' => $user,
        'team' => $team,
        'pipeline' => $pipeline,
        'openStages' => $pipeline->stages()->where('semantic_type', PipelineStageSemanticType::Open->value)->get(),
        'wonStage' => $pipeline->stages()->where('semantic_type', PipelineStageSemanticType::Won->value)->firstOrFail(),
        'lostStage' => $pipeline->stages()->where('semantic_type', PipelineStageSemanticType::Lost->value)->firstOrFail(),
        'customer' => Customer::factory()->create(['team_id' => $team->id]),
    ];
}

function hardeningDeal(array $context, array $overrides = []): Deal
{
    return app(DealService::class)->create($context['team'], [
        'title' => 'Hardening deal',
        'customer_id' => $context['customer']->id,
        'pipeline_id' => $context['pipeline']->id,
        'stage_id' => $context['openStages']->first()->id,
        'currency' => 'USD',
        'value_amount' => '100.00',
        ...$overrides,
    ], $context['user']);
}

test('pipeline services and routes cannot cross Team boundaries', function (): void {
    $context = hardeningSalesContext();
    $foreign = hardeningSalesContext();
    $service = app(PipelineService::class);
    $foreignStage = $foreign['openStages']->first();

    expect(fn () => $service->update($context['team'], $foreign['pipeline'], ['name' => 'Hijacked']))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $service->createStage($context['team'], $foreign['pipeline'], ['name' => 'Hijacked', 'semantic_type' => 'open']))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $service->reorderStages($context['team'], $foreign['pipeline'], [$foreignStage->id]))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $service->setDefault($context['team'], $foreign['pipeline']))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $service->delete($context['team'], $foreign['pipeline']))->toThrow(ModelNotFoundException::class);

    $this->actingAs($context['user'])->patch(route('pipelines.update', [$context['team']->slug, $foreign['pipeline']]), ['name' => 'Hijacked'])->assertNotFound();
    $this->actingAs($context['user'])->post(route('pipeline-stages.store', [$context['team']->slug, $foreign['pipeline']]), ['name' => 'Hijacked', 'semantic_type' => 'open'])->assertNotFound();
    $this->actingAs($context['user'])->post(route('pipeline-stages.reorder', [$context['team']->slug, $foreign['pipeline']]), ['stage_ids' => [$foreignStage->id]])->assertNotFound();
    $this->actingAs($context['user'])->post(route('pipelines.default', [$context['team']->slug, $foreign['pipeline']]))->assertNotFound();
    $this->actingAs($context['user'])->delete(route('pipelines.destroy', [$context['team']->slug, $foreign['pipeline']]))->assertNotFound();
});

test('each Team has exactly one default pipeline and switching is isolated', function (): void {
    $context = hardeningSalesContext();
    $foreign = hardeningSalesContext();
    $service = app(PipelineService::class);
    $secondary = $service->create($context['team'], ['name' => 'Secondary', 'with_default_stages' => true], $context['user']);

    $service->setDefault($context['team'], $secondary);

    expect($context['team']->pipelines()->where('is_default', true)->count())->toBe(1)
        ->and($secondary->fresh()->is_default)->toBeTrue()
        ->and($foreign['team']->pipelines()->where('is_default', true)->count())->toBe(1)
        ->and($foreign['pipeline']->fresh()->is_default)->toBeTrue();

    expect(fn () => DB::transaction(fn () => Pipeline::query()->create(['team_id' => $context['team']->id, 'name' => 'Illegal default', 'is_default' => true, 'is_active' => true])))->toThrow(QueryException::class);
});

test('default provisioning is idempotent for empty and custom Teams', function (): void {
    $emptyTeam = Team::factory()->create();
    $first = app(PipelineService::class)->ensureDefault($emptyTeam);
    $second = app(PipelineService::class)->ensureDefault($emptyTeam);

    expect($first['created'])->toBeTrue()
        ->and($second['created'])->toBeFalse()
        ->and($emptyTeam->pipelines()->count())->toBe(1)
        ->and($emptyTeam->pipelines()->where('is_default', true)->count())->toBe(1);

    $context = hardeningSalesContext();
    $custom = app(PipelineService::class)->create($context['team'], ['name' => 'Custom', 'is_default' => true, 'with_default_stages' => false]);
    $result = app(PipelineService::class)->ensureDefault($context['team']);

    expect($result['created'])->toBeFalse()
        ->and($result['pipeline']->id)->toBe($custom->id)
        ->and($context['team']->pipelines()->count())->toBe(2)
        ->and($context['team']->pipelines()->where('is_default', true)->count())->toBe(1);
});

test('free Team creation provisions a usable default pipeline', function (): void {
    $user = User::factory()->create();
    $team = app(CreateTeam::class)->handle($user, 'Provisioned Team');

    expect($team->pipelines()->where('is_default', true)->with('stages')->first()?->stages)->toHaveCount(6);
});

test('default stages have deterministic order, semantic types, and valid probabilities', function (): void {
    $context = hardeningSalesContext();
    $stages = $context['pipeline']->stages()->get();

    expect($stages->pluck('sort_order')->all())->toBe([1, 2, 3, 4, 5, 6])
        ->and($stages->where('semantic_type', PipelineStageSemanticType::Open)->count())->toBe(4)
        ->and($stages->where('semantic_type', PipelineStageSemanticType::Won)->count())->toBe(1)
        ->and($stages->where('semantic_type', PipelineStageSemanticType::Lost)->count())->toBe(1)
        ->and($stages->pluck('probability')->map(fn ($probability): float => (float) $probability)->every(fn (float $probability): bool => $probability >= 0 && $probability <= 100))->toBeTrue();

    expect(fn () => app(PipelineService::class)->createStage($context['team'], $context['pipeline'], ['name' => 'Another won', 'semantic_type' => 'won']))->toThrow(ValidationException::class)
        ->and(fn () => app(PipelineService::class)->createStage($context['team'], $context['pipeline'], ['name' => 'Another lost', 'semantic_type' => 'lost']))->toThrow(ValidationException::class);

    expect(fn () => DB::transaction(fn () => PipelineStage::query()->create(['team_id' => $context['team']->id, 'pipeline_id' => $context['pipeline']->id, 'name' => 'Illegal won', 'sort_order' => 99, 'semantic_type' => 'won'])))->toThrow(QueryException::class);
});

test('stage reorder is deterministic, isolated, and rolls back invalid submissions', function (): void {
    $context = hardeningSalesContext();
    $service = app(PipelineService::class);
    $pipeline = $service->create($context['team'], ['name' => 'Reorder', 'with_default_stages' => false]);
    $stages = collect(['A', 'B', 'C', 'D'])->map(fn (string $name) => $service->createStage($context['team'], $pipeline, ['name' => $name, 'semantic_type' => 'open']))->values();
    $other = $service->create($context['team'], ['name' => 'Other', 'with_default_stages' => false]);
    $otherStage = $service->createStage($context['team'], $other, ['name' => 'Other stage', 'semantic_type' => 'open']);

    $service->reorderStages($context['team'], $pipeline, [$stages[3]->id, $stages[1]->id, $stages[0]->id, $stages[2]->id]);
    $ordered = $pipeline->stages()->pluck('name', 'sort_order')->all();

    expect($ordered)->toBe([1000 => 'D', 2000 => 'B', 3000 => 'A', 4000 => 'C'])
        ->and($pipeline->stages()->pluck('sort_order')->unique())->toHaveCount(4)
        ->and($otherStage->fresh()->sort_order)->toBe(1);

    expect(fn () => $service->reorderStages($context['team'], $pipeline, [$stages[0]->id, $stages[1]->id]))->toThrow(ValidationException::class);
    expect($pipeline->stages()->pluck('name', 'sort_order')->all())->toBe($ordered);
});

test('stage deletion protects Deals and keeps lifecycle pipelines usable', function (): void {
    $context = hardeningSalesContext();
    $service = app(PipelineService::class);
    $emptyPipeline = $service->create($context['team'], ['name' => 'Empty stages', 'with_default_stages' => false]);
    $emptyOpenStages = collect(['A', 'B'])->map(fn (string $name) => $service->createStage($context['team'], $emptyPipeline, ['name' => $name, 'semantic_type' => 'open']));
    $service->deleteStage($context['team'], $emptyOpenStages->first());
    expect(PipelineStage::query()->whereKey($emptyOpenStages->first()->id)->exists())->toBeFalse();
    expect(fn () => $service->deleteStage($context['team'], $emptyOpenStages->last()))->toThrow(ValidationException::class);

    $dealStage = $context['openStages'][1];
    $deal = hardeningDeal($context, ['stage_id' => $dealStage->id]);
    expect(fn () => $service->deleteStage($context['team'], $dealStage))->toThrow(ValidationException::class)
        ->and(fn () => $service->deleteStage($context['team'], $context['wonStage']))->toThrow(ValidationException::class);

    expect($deal->fresh()->stage_id)->toBe($dealStage->id);
});

test('pipeline deletion is safe for Deals and default state', function (): void {
    $context = hardeningSalesContext();
    $service = app(PipelineService::class);
    $empty = $service->create($context['team'], ['name' => 'Empty', 'with_default_stages' => false]);
    $service->delete($context['team'], $empty);
    expect(Pipeline::query()->whereKey($empty->id)->exists())->toBeFalse();

    expect(fn () => $service->delete($context['team'], $context['pipeline']))->toThrow(ValidationException::class);

    $withDeal = $service->create($context['team'], ['name' => 'With deal', 'with_default_stages' => true]);
    $deal = hardeningDeal($context, ['pipeline_id' => $withDeal->id, 'stage_id' => $withDeal->stages()->where('semantic_type', 'open')->first()->id]);
    expect(fn () => $service->delete($context['team'], $withDeal))->toThrow(ValidationException::class)
        ->and(Deal::query()->whereKey($deal->id)->exists())->toBeTrue();
});

test('deal creation rejects foreign relations and mismatched pipeline stages', function (): void {
    $context = hardeningSalesContext();
    $foreign = hardeningSalesContext();
    $service = app(DealService::class);
    $data = ['title' => 'Relation test', 'pipeline_id' => $context['pipeline']->id, 'stage_id' => $context['openStages']->first()->id, 'currency' => 'USD'];
    $foreignBot = Bot::factory()->create(['team_id' => $foreign['team']->id]);
    $foreignLead = Lead::factory()->create(['team_id' => $foreign['team']->id, 'bot_id' => $foreignBot->id]);

    expect(fn () => $service->create($context['team'], [...$data, 'customer_id' => $foreign['customer']->id], $context['user']))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $service->create($context['team'], [...$data, 'lead_id' => $foreignLead->id], $context['user']))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $service->create($context['team'], [...$data, 'pipeline_id' => $foreign['pipeline']->id, 'stage_id' => $foreign['openStages']->first()->id], $context['user']))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $service->create($context['team'], [...$data, 'owner_user_id' => $foreign['user']->id], $context['user']))->toThrow(ValidationException::class);

    $otherPipeline = app(PipelineService::class)->create($context['team'], ['name' => 'Other stage owner', 'with_default_stages' => true]);
    expect(fn () => $service->create($context['team'], [...$data, 'pipeline_id' => $context['pipeline']->id, 'stage_id' => $otherPipeline->stages()->where('semantic_type', 'open')->first()->id], $context['user']))->toThrow(ModelNotFoundException::class);
});

test('lead/customer consistency normalizes to the Lead Customer and preserves conversion history', function (): void {
    $context = hardeningSalesContext();
    $otherCustomer = Customer::factory()->create(['team_id' => $context['team']->id]);
    $bot = Bot::factory()->create(['team_id' => $context['team']->id]);
    $lead = Lead::factory()->create(['team_id' => $context['team']->id, 'bot_id' => $bot->id, 'customer_id' => $context['customer']->id]);
    $deal = app(DealService::class)->createFromLead($context['team'], $lead, ['title' => 'Converted', 'customer_id' => $otherCustomer->id, 'pipeline_id' => $context['pipeline']->id, 'stage_id' => $context['openStages']->first()->id, 'currency' => 'USD'], $context['user']);

    expect($lead->fresh()->id)->toBe($lead->id)
        ->and($lead->fresh()->customer_id)->toBe($context['customer']->id)
        ->and($lead->fresh()->deals()->count())->toBe(1)
        ->and($deal->lead_id)->toBe($lead->id)
        ->and($deal->customer_id)->toBe($context['customer']->id);

    expect(fn () => app(DealService::class)->createFromLead($context['team'], $lead, ['pipeline_id' => $context['pipeline']->id, 'stage_id' => $context['openStages']->first()->id, 'currency' => 'USD'], $context['user']))->toThrow(ValidationException::class);
});

test('manual Deals allow null Leads and repeated customer relationships', function (): void {
    $context = hardeningSalesContext();
    $service = app(DealService::class);
    $first = hardeningDeal($context, ['value_amount' => null]);
    $second = hardeningDeal($context, ['title' => 'Second manual deal']);

    expect($first->lead_id)->toBeNull()
        ->and($first->value_amount)->toBeNull()
        ->and($second->customer_id)->toBe($context['customer']->id)
        ->and($context['customer']->deals()->count())->toBe(2);
});

test('deal money and currency request validation is strict while decimal values are preserved', function (): void {
    $context = hardeningSalesContext();
    $payload = ['title' => 'Money deal', 'pipeline_id' => $context['pipeline']->id, 'stage_id' => $context['openStages']->first()->id, 'currency' => 'USD', 'value_amount' => '100.25'];
    $this->actingAs($context['user'])->post(route('deals.store', $context['team']->slug), $payload)->assertRedirect();
    expect(Deal::query()->latest('id')->firstOrFail()->value_amount)->toBe('100.25');

    foreach ([['value_amount' => '-1'], ['value_amount' => 'not-a-number'], ['value_amount' => '100.123'], ['value_amount' => '10000000000000000.00'], ['currency' => 'usd'], ['currency' => 'USDX']] as $invalid) {
        $this->actingAs($context['user'])->post(route('deals.store', $context['team']->slug), [...$payload, ...$invalid])->assertSessionHasErrors(array_key_first($invalid));
    }
});

test('mixed currency metrics stay grouped and win rate excludes open Deals', function (): void {
    $context = hardeningSalesContext();
    $service = app(DealService::class);
    $usdA = hardeningDeal($context, ['value_amount' => '100.00', 'currency' => 'USD']);
    $usdB = hardeningDeal($context, ['value_amount' => '200.00', 'currency' => 'USD']);
    $eur = hardeningDeal($context, ['value_amount' => '500.00', 'currency' => 'EUR']);
    $service->markWon($context['team'], $usdA, $context['user']);
    $service->markLost($context['team'], $usdB, 'No budget', $context['user']);
    $payload = $service->index($context['team'], [], 'list');
    $byCurrency = collect($payload['metrics']['byCurrency'])->keyBy('currency');

    expect($byCurrency['USD']['openValue'])->toBe('0.00')
        ->and($byCurrency['USD']['wonValue'])->toBe('100.00')
        ->and($byCurrency['EUR']['openValue'])->toBe('500.00')
        ->and($payload['metrics']['winRate'])->toBe(50.0)
        ->and($payload['metrics']['openCount'])->toBe(1);
});

test('open movement, lifecycle transitions, reopen, and activity records are consistent', function (): void {
    $context = hardeningSalesContext();
    $service = app(DealService::class);
    $deal = hardeningDeal($context);
    $nextOpen = $context['openStages'][1];
    $createdActivityCount = CustomerActivity::query()->where('related_id', $deal->id)->count();
    Carbon::setTestNow(now()->addMinute());
    $service->moveStage($context['team'], $deal, $nextOpen, $context['user']);
    $moved = $deal->fresh();

    expect($moved->status)->toBe(DealStatus::Open)
        ->and($moved->stage_id)->toBe($nextOpen->id)
        ->and($moved->won_at)->toBeNull()
        ->and($moved->lost_at)->toBeNull()
        ->and(CustomerActivity::query()->where('related_id', $deal->id)->count())->toBe($createdActivityCount + 1);

    $service->markWon($context['team'], $deal, $context['user']);
    $won = $deal->fresh();
    expect($won->status)->toBe(DealStatus::Won)->and($won->won_at)->not->toBeNull()->and($won->lost_at)->toBeNull()->and($won->lost_reason)->toBeNull();
    expect(CustomerActivity::query()->where('related_id', $deal->id)->where('type', CustomerActivityType::DealWon->value)->count())->toBe(1);

    expect(fn () => $service->moveStage($context['team'], $deal, $context['openStages']->first(), $context['user']))->toThrow(ValidationException::class);
    $service->reopen($context['team'], $deal, $context['openStages']->first(), $context['user']);
    expect($deal->fresh()->status)->toBe(DealStatus::Open)->and($deal->fresh()->won_at)->toBeNull()->and($deal->fresh()->lost_at)->toBeNull()->and($deal->fresh()->lost_reason)->toBeNull();
    expect(CustomerActivity::query()->where('related_id', $deal->id)->where('type', CustomerActivityType::DealReopened->value)->count())->toBe(1);

    $service->markLost($context['team'], $deal, 'No budget', $context['user']);
    expect($deal->fresh()->status)->toBe(DealStatus::Lost)->and($deal->fresh()->lost_at)->not->toBeNull()->and($deal->fresh()->won_at)->toBeNull()->and($deal->fresh()->lost_reason)->toBe('No budget');
    expect(fn () => $service->moveStage($context['team'], $deal, $context['openStages']->first(), $context['user']))->toThrow(ValidationException::class);
    $service->reopen($context['team'], $deal, $context['openStages']->first(), $context['user']);
    expect($deal->fresh()->status)->toBe(DealStatus::Open);
});

test('invalid reopen targets and closed Deal edits cannot bypass lifecycle rules', function (): void {
    $context = hardeningSalesContext();
    $foreign = hardeningSalesContext();
    $service = app(DealService::class);
    $deal = hardeningDeal($context);
    $service->markWon($context['team'], $deal, $context['user']);

    expect(fn () => $service->reopen($context['team'], $deal, $context['wonStage'], $context['user']))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $service->reopen($context['team'], $deal, $context['lostStage'], $context['user']))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $service->reopen($context['team'], $deal, $foreign['openStages']->first(), $context['user']))->toThrow(ModelNotFoundException::class);

    $service->update($context['team'], $deal, ['title' => 'Closed edit', 'description' => 'Safe', 'pipeline_id' => $context['pipeline']->id, 'stage_id' => $context['wonStage']->id, 'currency' => 'USD', 'status' => 'open', 'won_at' => null, 'lost_at' => null], $context['user']);
    expect($deal->fresh()->status)->toBe(DealStatus::Won)->and($deal->fresh()->won_at)->not->toBeNull();
    expect(fn () => $service->update($context['team'], $deal, ['title' => 'Bad movement', 'pipeline_id' => $context['pipeline']->id, 'stage_id' => $context['openStages']->first()->id, 'currency' => 'USD'], $context['user']))->toThrow(ValidationException::class);
});

test('owner and expected-close validation and metrics boundaries are correct', function (): void {
    $context = hardeningSalesContext();
    $foreign = hardeningSalesContext();
    $member = User::factory()->create();
    $context['team']->members()->attach($member, ['role' => TeamRole::Member->value]);
    $service = app(DealService::class);
    $deal = hardeningDeal($context, ['owner_user_id' => $member->id, 'expected_close_date' => today()->addDays(30)]);
    $before = $deal->fresh()->last_activity_at;
    Carbon::setTestNow(now()->addMinute());
    $service->update($context['team'], $deal, ['title' => 'Owner changed', 'pipeline_id' => $context['pipeline']->id, 'stage_id' => $context['openStages']->first()->id, 'owner_user_id' => null, 'currency' => 'USD', 'value_amount' => '100.00', 'expected_close_date' => today()->addDays(30)], $context['user']);
    expect($deal->fresh()->owner_user_id)->toBeNull()->and($deal->fresh()->last_activity_at)->not->toEqual($before)->and(CustomerActivity::query()->where('related_id', $deal->id)->where('type', CustomerActivityType::DealOwnerChanged->value)->count())->toBe(1);
    $service->update($context['team'], $deal, ['title' => 'Value changed', 'pipeline_id' => $context['pipeline']->id, 'stage_id' => $context['openStages']->first()->id, 'owner_user_id' => null, 'currency' => 'USD', 'value_amount' => '150.00', 'expected_close_date' => today()->addDays(29)], $context['user']);
    expect(CustomerActivity::query()->where('related_id', $deal->id)->where('type', CustomerActivityType::DealValueChanged->value)->count())->toBe(1)
        ->and(CustomerActivity::query()->where('related_id', $deal->id)->where('type', CustomerActivityType::DealExpectedCloseChanged->value)->count())->toBe(1);
    expect(fn () => $service->update($context['team'], $deal, ['title' => 'Foreign owner', 'pipeline_id' => $context['pipeline']->id, 'stage_id' => $context['openStages']->first()->id, 'owner_user_id' => $foreign['user']->id, 'currency' => 'USD'], $context['user']))->toThrow(ValidationException::class);

    hardeningDeal($context, ['title' => 'Overdue', 'expected_close_date' => today()->subDay()]);
    $soon = hardeningDeal($context, ['title' => 'Close soon', 'expected_close_date' => today()->addDays(30)]);
    $closed = hardeningDeal($context, ['title' => 'Closed soon', 'expected_close_date' => today()->addDays(1)]);
    $service->markWon($context['team'], $closed, $context['user']);
    $metrics = $service->index($context['team'], [], 'board')['metrics'];
    expect($metrics['overdueCount'])->toBe(1)->and($metrics['closeSoonCount'])->toBe(2)->and($soon->fresh()->expected_close_date->toDateString())->toBe(today()->addDays(30)->toDateString());
});

test('customer merge preserves Deal identity, linkage, metrics, and activity timeline', function (): void {
    $context = hardeningSalesContext();
    $destination = Customer::factory()->create(['team_id' => $context['team']->id]);
    $dealA = hardeningDeal($context, ['title' => 'Deal A']);
    $dealB = hardeningDeal($context, ['title' => 'Deal B']);
    app(DealService::class)->markWon($context['team'], $dealB, $context['user']);
    $dealIds = [$dealA->id, $dealB->id];

    app(CustomerMergeService::class)->merge($context['team'], $context['customer'], $destination, $context['user']);
    $profile = app(CustomerService::class)->detail($context['team'], $destination)['customer'];

    expect(Deal::query()->whereIn('id', $dealIds)->pluck('id')->sort()->values()->all())->toBe($dealIds)
        ->and(Deal::query()->whereIn('id', $dealIds)->where('customer_id', $destination->id)->count())->toBe(2)
        ->and($profile['counts']['deals'])->toBe(2)
        ->and($profile['counts']['wonDeals'])->toBe(1)
        ->and(collect($profile['timeline'])->pluck('type'))->toContain(CustomerActivityType::DealCreated->value, CustomerActivityType::DealWon->value);
});

test('deal search, filters, pagination, and selected board pipeline are scoped correctly', function (): void {
    $context = hardeningSalesContext();
    $foreign = hardeningSalesContext();
    $service = app(DealService::class);
    $secondary = app(PipelineService::class)->create($context['team'], ['name' => 'Secondary', 'with_default_stages' => true]);
    $secondaryOpen = $secondary->stages()->where('semantic_type', 'open')->first();
    $member = User::factory()->create();
    $context['team']->members()->attach($member, ['role' => TeamRole::Member->value]);
    $deal = hardeningDeal($context, ['title' => 'Alpha Opportunity', 'owner_user_id' => $member->id]);
    Customer::query()->whereKey($context['customer']->id)->update(['display_name' => 'Searchable Customer', 'email' => 'search@example.com']);
    $other = hardeningDeal($context, ['title' => 'Secondary Opportunity', 'pipeline_id' => $secondary->id, 'stage_id' => $secondaryOpen->id]);
    $closedOther = hardeningDeal($context, ['title' => 'Closed Secondary Opportunity', 'pipeline_id' => $secondary->id, 'stage_id' => $secondaryOpen->id]);
    $service->markWon($context['team'], $closedOther, $context['user']);
    hardeningDeal($foreign, ['title' => 'Foreign Opportunity']);

    $list = $service->index($context['team'], ['search' => 'search@example.com', 'owner_user_id' => $member->id, 'status' => 'open'], 'list');
    expect($list['deals']->total())->toBe(1)->and($list['deals']->first()['title'])->toBe($deal->title);
    $board = $service->index($context['team'], ['pipeline_id' => $secondary->id], 'board');
    expect(collect($board['stages'])->pluck('id')->all())->toBe($secondary->stages()->pluck('id')->all())
        ->and(collect($board['deals'])->pluck('deals')->flatten(1)->pluck('id')->all())->toContain($other->id, $closedOther->id)->not->toContain($deal->id);
});

test('RBAC, Team isolation, and navigation permission maps are enforced server-side', function (): void {
    $context = hardeningSalesContext();
    $deal = hardeningDeal($context);
    $analyst = User::factory()->create();
    $context['team']->members()->attach($analyst, ['role' => TeamRole::Analyst->value]);
    $analyst->switchTeam($context['team']);
    $contentManager = User::factory()->create();
    $context['team']->members()->attach($contentManager, ['role' => TeamRole::ContentManager->value]);

    expect(Gate::forUser($analyst)->allows('view', $deal))->toBeTrue()
        ->and(Gate::forUser($analyst)->allows('update', $deal))->toBeFalse();
    $this->actingAs($analyst)->get(route('deals.show', [$context['team']->slug, $deal]))->assertSuccessful();
    $this->actingAs($analyst)->post(route('deals.won', [$context['team']->slug, $deal]))->assertForbidden();
    $this->actingAs($analyst)->post(route('pipelines.store', $context['team']->slug), ['name' => 'Denied'])->assertForbidden();
    $this->actingAs($contentManager)->get(route('deals.index', $context['team']->slug))->assertForbidden();

    $foreign = hardeningSalesContext();
    $this->actingAs($analyst)->get(route('deals.show', [$context['team']->slug, hardeningDeal($foreign)]))->assertNotFound();
    expect(Gate::forUser($analyst)->allows('view', $foreign['pipeline']))->toBeFalse();
});

test('deal and pipeline actions never create ToolRuns and Inertia props serialize', function (): void {
    $context = hardeningSalesContext();
    $service = app(DealService::class);
    $pipelineService = app(PipelineService::class);
    $before = ToolRun::query()->count();
    $deal = hardeningDeal($context);
    $pipeline = $pipelineService->create($context['team'], ['name' => 'ToolRun safe', 'with_default_stages' => false]);
    $stage = $pipelineService->createStage($context['team'], $pipeline, ['name' => 'Open', 'semantic_type' => 'open']);
    $pipelineService->reorderStages($context['team'], $pipeline, [$stage->id]);
    $service->moveStage($context['team'], $deal, $context['openStages'][1], $context['user']);
    $service->markWon($context['team'], $deal, $context['user']);
    $service->reopen($context['team'], $deal, $context['openStages']->first(), $context['user']);
    $service->markLost($context['team'], $deal, 'Closed', $context['user']);

    expect(ToolRun::query()->count())->toBe($before);
    $this->actingAs($context['user'])->get(route('deals.index', $context['team']->slug))->assertInertia(fn (Assert $page) => $page->component('deals/index')->has('metrics')->has('stages'));
    $this->actingAs($context['user'])->get(route('deals.index', [$context['team']->slug, 'view' => 'list']))->assertInertia(fn (Assert $page) => $page->component('deals/index')->where('view', 'list')->has('deals.data'));
    $this->actingAs($context['user'])->get(route('deals.create', $context['team']->slug))->assertInertia(fn (Assert $page) => $page->component('deals/create')->has('pipelineOptions'));
    $this->actingAs($context['user'])->get(route('deals.show', [$context['team']->slug, $deal]))->assertInertia(fn (Assert $page) => $page->component('deals/show')->where('deal.id', $deal->id));
    $this->actingAs($context['user'])->get(route('deals.pipelines', $context['team']->slug))->assertInertia(fn (Assert $page) => $page->component('deals/pipelines')->has('pipelines'));
});

test('Lead to Deal HTTP conversion and navigation permissions are wired', function (): void {
    $context = hardeningSalesContext();
    $bot = Bot::factory()->create(['team_id' => $context['team']->id]);
    $lead = Lead::factory()->create(['team_id' => $context['team']->id, 'bot_id' => $bot->id, 'customer_id' => $context['customer']->id]);

    $this->actingAs($context['user'])
        ->post(route('leads.deals.store', [$context['team']->slug, $lead]), [
            'title' => 'Converted by route',
            'pipeline_id' => $context['pipeline']->id,
            'stage_id' => $context['openStages']->first()->id,
            'currency' => 'USD',
        ])
        ->assertRedirect();

    expect($lead->fresh()->deals()->count())->toBe(1);
    $this->actingAs($context['user'])->get(route('deals.index', $context['team']->slug))->assertSuccessful();
    expect($context['user']->toTeamPermissions($context['team'])->abilities['deals.view'])->toBeTrue();
});
