<?php

use App\Enums\DealStatus;
use App\Enums\PipelineStageSemanticType;
use App\Enums\TeamRole;
use App\Models\Bot;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\ToolRun;
use App\Models\User;
use App\Services\Customers\CustomerMergeService;
use App\Services\Deals\DealService;
use App\Services\Deals\PipelineService;
use Illuminate\Validation\ValidationException;

function salesContext(): array
{
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $pipeline = app(PipelineService::class)->ensureDefault($team)['pipeline'];
    $openStage = $pipeline->stages()->where('semantic_type', PipelineStageSemanticType::Open->value)->firstOrFail();
    $wonStage = $pipeline->stages()->where('semantic_type', PipelineStageSemanticType::Won->value)->firstOrFail();
    $lostStage = $pipeline->stages()->where('semantic_type', PipelineStageSemanticType::Lost->value)->firstOrFail();
    $customer = Customer::factory()->create(['team_id' => $team->id]);

    return compact('user', 'team', 'pipeline', 'openStage', 'wonStage', 'lostStage', 'customer');
}

test('teams receive one deterministic default pipeline with semantic stages', function (): void {
    $context = salesContext();
    $team = $context['team'];

    expect($team->pipelines()->where('is_default', true)->count())->toBe(1)
        ->and($context['pipeline']->name)->toBe('Sales Pipeline')
        ->and($context['pipeline']->stages()->count())->toBe(6)
        ->and($context['pipeline']->stages()->where('semantic_type', 'won')->count())->toBe(1)
        ->and($context['pipeline']->stages()->where('semantic_type', 'lost')->count())->toBe(1);

    expect(app(PipelineService::class)->ensureDefault($team)['created'])->toBeFalse()
        ->and($team->pipelines()->count())->toBe(1);
});

test('deals are team scoped and transition through open won lost and explicit reopen states', function (): void {
    $context = salesContext();
    $service = app(DealService::class);
    $deal = $service->create($context['team'], ['title' => 'Enterprise renewal', 'customer_id' => $context['customer']->id, 'pipeline_id' => $context['pipeline']->id, 'stage_id' => $context['openStage']->id, 'value_amount' => '1200.00', 'currency' => 'USD'], $context['user']);

    $service->markWon($context['team'], $deal, $context['user']);
    expect($deal->fresh()->status)->toBe(DealStatus::Won)->and($deal->fresh()->won_at)->not->toBeNull();
    expect(fn () => $service->moveStage($context['team'], $deal, $context['openStage'], $context['user']))->toThrow(ValidationException::class);

    $service->reopen($context['team'], $deal, $context['openStage'], $context['user']);
    expect($deal->fresh()->status)->toBe(DealStatus::Open)->and($deal->fresh()->won_at)->toBeNull();
    $service->markLost($context['team'], $deal, 'Budget paused', $context['user']);
    expect($deal->fresh()->status)->toBe(DealStatus::Lost)->and($deal->fresh()->lost_reason)->toBe('Budget paused');
});

test('lead conversion reuses its customer and is idempotently guarded', function (): void {
    $context = salesContext();
    $bot = Bot::factory()->create(['team_id' => $context['team']->id]);
    $lead = Lead::factory()->create(['team_id' => $context['team']->id, 'bot_id' => $bot->id, 'customer_id' => $context['customer']->id]);
    $data = ['pipeline_id' => $context['pipeline']->id, 'stage_id' => $context['openStage']->id, 'currency' => 'USD', 'value_amount' => 500];

    $deal = app(DealService::class)->createFromLead($context['team'], $lead, $data, $context['user']);
    expect($deal->lead_id)->toBe($lead->id)->and($deal->customer_id)->toBe($context['customer']->id)->and($lead->fresh()->deals()->count())->toBe(1);
    expect(fn () => app(DealService::class)->createFromLead($context['team'], $lead, $data, $context['user']))->toThrow(ValidationException::class);
});

test('customer merge reassigns deals without changing their identity or history', function (): void {
    $context = salesContext();
    $destination = Customer::factory()->create(['team_id' => $context['team']->id]);
    $deal = app(DealService::class)->create($context['team'], ['title' => 'Merged deal', 'customer_id' => $context['customer']->id, 'pipeline_id' => $context['pipeline']->id, 'stage_id' => $context['openStage']->id, 'currency' => 'EUR'], $context['user']);
    app(CustomerMergeService::class)->merge($context['team'], $context['customer'], $destination, $context['user']);

    expect($deal->fresh()->customer_id)->toBe($destination->id)->and(Deal::query()->whereKey($deal->id)->exists())->toBeTrue();
});

test('deal index reports mixed currency metrics and analyst access is read only', function (): void {
    $context = salesContext();
    $service = app(DealService::class);
    foreach ([['USD', 100], ['EUR', 200]] as [$currency, $value]) {
        $service->create($context['team'], ['title' => $currency.' deal', 'customer_id' => $context['customer']->id, 'pipeline_id' => $context['pipeline']->id, 'stage_id' => $context['openStage']->id, 'currency' => $currency, 'value_amount' => $value], $context['user']);
    }
    $payload = $service->index($context['team'], [], 'board');
    expect($payload['metrics']['openCount'])->toBe(2)->and($payload['metrics']['byCurrency'])->toHaveCount(2);

    $analyst = User::factory()->create();
    $context['team']->members()->attach($analyst, ['role' => TeamRole::Analyst->value]);
    $analyst->switchTeam($context['team']);
    $this->actingAs($analyst)->get(route('deals.index', $context['team']->slug))->assertSuccessful();
    $this->actingAs($analyst)->post(route('deals.store', $context['team']->slug), [])->assertForbidden();
});

test('deal records never create tool runs', function (): void {
    $context = salesContext();
    $before = ToolRun::query()->count();
    app(DealService::class)->create($context['team'], ['title' => 'Manual deal', 'pipeline_id' => $context['pipeline']->id, 'stage_id' => $context['openStage']->id, 'currency' => 'USD'], $context['user']);

    expect(ToolRun::query()->count())->toBe($before);
});
