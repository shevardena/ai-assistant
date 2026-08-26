<?php

namespace App\Services\Deals;

use App\Enums\CustomerActivityType;
use App\Enums\DealStatus;
use App\Enums\PipelineStageSemanticType;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Team;
use App\Models\User;
use App\Services\Customers\CustomerActivityService;
use App\Services\Tasks\TaskService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class DealService
{
    public function __construct(
        private readonly PipelineService $pipelines,
        private readonly CustomerActivityService $activities,
        private readonly TaskService $tasks,
    ) {}

    /** @param array<string, mixed> $filters */
    public function index(Team $team, array $filters, string $view = 'board'): array
    {
        $this->pipelines->ensureDefault($team);
        $query = $this->filteredQuery($team, $filters);
        $selectedPipeline = $this->selectedPipeline($team, $filters['pipeline_id'] ?? null);
        $stages = $selectedPipeline?->stages()->get() ?? collect();
        $deals = (clone $query)->with(['customer:id,display_name,email', 'owner:id,name', 'pipeline:id,name', 'stage:id,name,sort_order,semantic_type'])->latest('updated_at')->latest('id');

        return [
            'view' => $view === 'list' ? 'list' : 'board',
            'filters' => $this->filterPayload($filters, $selectedPipeline),
            'pipelines' => $team->pipelines()->where('is_active', true)->with('stages')->orderByDesc('is_default')->orderBy('name')->get()->map(fn (Pipeline $pipeline): array => $this->pipelineOption($pipeline))->values()->all(),
            'ownerOptions' => $team->members()->select('users.id', 'users.name')->orderBy('name')->get()->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])->values()->all(),
            'metrics' => $this->metrics($query),
            'stages' => $stages->map(fn (PipelineStage $stage): array => ['id' => $stage->id, 'name' => $stage->name, 'sortOrder' => $stage->sort_order, 'semanticType' => $stage->semantic_type->value, 'probability' => $this->decimal($stage->probability)])->values()->all(),
            'deals' => $view === 'list'
                ? $deals->paginate(25)->withQueryString()->through(fn (Deal $deal): array => $this->listItem($deal))
                : $this->board($deals, $stages),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(Team $team, Deal $deal): array
    {
        $deal = $team->deals()->whereKey($deal->getKey())->with(['customer:id,display_name,email,phone', 'lead:id,public_id,name,customer_id', 'pipeline:id,name', 'stage:id,name,semantic_type,probability', 'owner:id,name'])->firstOrFail();
        $activities = $deal->customer_id === null ? collect() : $deal->customer?->activities()->where('related_type', $deal->getMorphClass())->where('related_id', $deal->id)->with('actor:id,name')->latest('occurred_at')->get() ?? collect();

        return [
            'deal' => $this->detailItem($deal, $activities->map(fn ($activity): array => ['type' => $activity->type, 'title' => $activity->title, 'description' => $activity->description, 'actor' => $activity->actor?->name, 'timestamp' => $activity->occurred_at?->toAtomString()])->values()->all()),
            'tasks' => $this->tasks->forDeal($team, $deal),
            ...$this->formOptions($team, $deal->pipeline_id),
        ];
    }

    /** @return array<string, mixed> */
    public function formOptions(Team $team, ?int $selectedPipelineId = null): array
    {
        $this->pipelines->ensureDefault($team);
        $pipelines = $team->pipelines()->where('is_active', true)->with('stages')->orderByDesc('is_default')->orderBy('name')->get();

        return [
            'pipelineOptions' => $pipelines->map(fn (Pipeline $pipeline): array => $this->pipelineOption($pipeline))->values()->all(),
            'customerOptions' => $team->customers()->whereNull('merged_into_customer_id')->select('id', 'display_name', 'email')->orderBy('display_name')->limit(200)->get()->map(fn (Customer $customer): array => ['id' => $customer->id, 'name' => $customer->name, 'email' => $customer->email])->values()->all(),
            'leadOptions' => $team->leads()->with('customer:id,display_name')->select('id', 'public_id', 'name', 'email', 'customer_id')->latest()->limit(200)->get()->map(fn (Lead $lead): array => ['id' => $lead->id, 'reference' => $lead->public_id, 'name' => $lead->name ?? 'Unnamed lead', 'email' => $lead->email, 'customerId' => $lead->customer_id])->values()->all(),
            'currencyOptions' => ['USD', 'EUR', 'GBP', 'GEL', 'CAD', 'AUD'],
            'selectedPipelineId' => $selectedPipelineId ?? $pipelines->firstWhere('is_default', true)?->id ?? $pipelines->first()?->id,
        ];
    }

    /** @param array<string, mixed> $data */
    public function create(Team $team, array $data, User $actor): Deal
    {
        return DB::transaction(function () use ($team, $data, $actor): Deal {
            [$pipeline, $stage, $customer, $lead] = $this->validatedRelations($team, $data);
            if ($lead !== null && $team->deals()->where('lead_id', $lead->id)->exists()) {
                throw ValidationException::withMessages(['lead_id' => 'A Deal has already been created from this Lead.']);
            }
            if ($stage->semantic_type !== PipelineStageSemanticType::Open) {
                throw ValidationException::withMessages(['stage_id' => 'New Deals must start in an open Stage.']);
            }

            $deal = $team->deals()->create([
                'customer_id' => $customer?->id,
                'lead_id' => $lead?->id,
                'pipeline_id' => $pipeline->id,
                'stage_id' => $stage->id,
                'owner_user_id' => $this->ownerId($team, $data['owner_user_id'] ?? null),
                'title' => trim((string) $data['title']),
                'description' => $this->nullableText($data['description'] ?? null, 5000),
                'value_amount' => $data['value_amount'] ?? null,
                'currency' => strtoupper((string) $data['currency']),
                'probability' => $data['probability'] ?? $stage->probability,
                'expected_close_date' => $data['expected_close_date'] ?? null,
                'status' => DealStatus::Open,
                'source' => $data['source'] ?? 'manual',
                'last_activity_at' => now(),
            ]);

            $this->record($team, $deal, CustomerActivityType::DealCreated, 'Deal created', $actor);

            return $deal->load(['customer', 'pipeline', 'stage', 'owner']);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Team $team, Deal $deal, array $data, User $actor): Deal
    {
        return DB::transaction(function () use ($team, $deal, $data, $actor): Deal {
            $deal = $team->deals()->whereKey($deal->getKey())->with(['stage', 'pipeline'])->lockForUpdate()->firstOrFail();
            $old = $deal->only(['stage_id', 'owner_user_id', 'value_amount', 'expected_close_date', 'pipeline_id']);
            $pipeline = $team->pipelines()->whereKey((int) ($data['pipeline_id'] ?? $deal->pipeline_id))->firstOrFail();
            $stage = $pipeline->stages()->whereKey((int) ($data['stage_id'] ?? $deal->stage_id))->firstOrFail();
            $stageChanged = (int) $old['stage_id'] !== $stage->id || (int) $old['pipeline_id'] !== $pipeline->id;
            if ($stageChanged && ($deal->status !== DealStatus::Open || $stage->semantic_type !== PipelineStageSemanticType::Open)) {
                throw ValidationException::withMessages(['stage_id' => 'Use an explicit lifecycle action to close or reopen a Deal.']);
            }

            $deal->update([
                'pipeline_id' => $pipeline->id,
                'stage_id' => $stage->id,
                'owner_user_id' => $this->ownerId($team, $data['owner_user_id'] ?? null),
                'title' => trim((string) $data['title']),
                'description' => $this->nullableText($data['description'] ?? null, 5000),
                'value_amount' => $data['value_amount'] ?? null,
                'currency' => strtoupper((string) $data['currency']),
                'probability' => $data['probability'] ?? $stage->probability,
                'expected_close_date' => $data['expected_close_date'] ?? null,
                'last_activity_at' => now(),
            ]);
            if ($stageChanged) {
                $this->record($team, $deal, CustomerActivityType::DealStageChanged, 'Deal stage changed', $actor, $stage->name);
            }
            if ((int) $old['owner_user_id'] !== (int) $deal->owner_user_id) {
                $this->record($team, $deal, CustomerActivityType::DealOwnerChanged, 'Deal owner changed', $actor, $deal->owner?->name);
            }
            if ((string) $old['value_amount'] !== (string) $deal->value_amount) {
                $this->record($team, $deal, CustomerActivityType::DealValueChanged, 'Deal value changed', $actor);
            }
            if ((string) $old['expected_close_date'] !== (string) $deal->expected_close_date) {
                $this->record($team, $deal, CustomerActivityType::DealExpectedCloseChanged, 'Expected close date changed', $actor);
            }

            return $deal->fresh(['customer', 'pipeline', 'stage', 'owner']) ?? $deal;
        });
    }

    public function moveStage(Team $team, Deal $deal, PipelineStage $stage, User $actor): Deal
    {
        return $this->transition($team, $deal, $stage, $actor);
    }

    public function markWon(Team $team, Deal $deal, User $actor): Deal
    {
        $stage = $team->pipelineStages()->where('pipeline_id', $deal->pipeline_id)->where('semantic_type', PipelineStageSemanticType::Won->value)->firstOrFail();

        return $this->transition($team, $deal, $stage, $actor);
    }

    public function markLost(Team $team, Deal $deal, ?string $reason, User $actor): Deal
    {
        $stage = $team->pipelineStages()->where('pipeline_id', $deal->pipeline_id)->where('semantic_type', PipelineStageSemanticType::Lost->value)->firstOrFail();

        return $this->transition($team, $deal, $stage, $actor, $this->nullableText($reason, 2000));
    }

    public function reopen(Team $team, Deal $deal, PipelineStage $stage, User $actor): Deal
    {
        $stage = $team->pipelineStages()->whereKey($stage->getKey())->where('pipeline_id', $deal->pipeline_id)->where('semantic_type', PipelineStageSemanticType::Open->value)->firstOrFail();
        $deal = $team->deals()->whereKey($deal->getKey())->lockForUpdate()->firstOrFail();
        if ($deal->status === DealStatus::Open) {
            throw ValidationException::withMessages(['stage_id' => 'Only closed Deals can be reopened.']);
        }
        $deal->forceFill(['status' => DealStatus::Open, 'stage_id' => $stage->id, 'won_at' => null, 'lost_at' => null, 'lost_reason' => null, 'last_activity_at' => now()])->save();
        $this->record($team, $deal, CustomerActivityType::DealReopened, 'Deal reopened', $actor, $stage->name);

        return $deal->fresh(['customer', 'pipeline', 'stage', 'owner']) ?? $deal;
    }

    public function createFromLead(Team $team, Lead $lead, array $data, User $actor): Deal
    {
        $lead = $team->leads()->whereKey($lead->getKey())->with('customer')->firstOrFail();
        if ($team->deals()->where('lead_id', $lead->id)->exists()) {
            throw ValidationException::withMessages(['lead_id' => 'A Deal has already been created from this Lead.']);
        }
        $data['lead_id'] = $lead->id;
        $data['customer_id'] = $lead->customer_id ?? ($data['customer_id'] ?? null);
        $data['title'] = $data['title'] ?? ($lead->interest_summary ?: $lead->name ?: 'New Deal');

        return $this->create($team, $data, $actor);
    }

    /** @return array{pipeline: Pipeline, stage: PipelineStage, customer: Customer|null, lead: Lead|null} */
    private function validatedRelations(Team $team, array $data): array
    {
        $pipeline = $team->pipelines()->whereKey((int) $data['pipeline_id'])->firstOrFail();
        $stage = $pipeline->stages()->whereKey((int) $data['stage_id'])->firstOrFail();
        $customer = isset($data['customer_id']) && $data['customer_id'] !== '' ? $team->customers()->whereKey((int) $data['customer_id'])->firstOrFail() : null;
        $lead = isset($data['lead_id']) && $data['lead_id'] !== '' ? $team->leads()->whereKey((int) $data['lead_id'])->firstOrFail() : null;
        if ($lead?->customer_id !== null) {
            $customer = $lead->customer;
        }

        return [$pipeline, $stage, $customer, $lead];
    }

    private function transition(Team $team, Deal $deal, PipelineStage $stage, User $actor, ?string $lostReason = null): Deal
    {
        return DB::transaction(function () use ($team, $deal, $stage, $actor, $lostReason): Deal {
            $deal = $team->deals()->whereKey($deal->getKey())->lockForUpdate()->firstOrFail();
            $stage = $team->pipelineStages()->whereKey($stage->getKey())->where('pipeline_id', $deal->pipeline_id)->firstOrFail();
            $now = now();
            $status = match ($stage->semantic_type) {
                PipelineStageSemanticType::Won => DealStatus::Won,
                PipelineStageSemanticType::Lost => DealStatus::Lost,
                PipelineStageSemanticType::Open => DealStatus::Open,
            };
            if ($deal->status !== DealStatus::Open && $status === DealStatus::Open) {
                throw ValidationException::withMessages(['stage_id' => 'Reopen the Deal explicitly before moving it to an open Stage.']);
            }
            if ($deal->stage_id === $stage->id && $deal->status === $status) {
                if ($status === DealStatus::Lost && $lostReason !== null && $deal->lost_reason !== $lostReason) {
                    $deal->forceFill(['lost_reason' => $lostReason])->save();
                }

                return $deal->fresh(['customer', 'pipeline', 'stage', 'owner']) ?? $deal;
            }
            $deal->forceFill(['stage_id' => $stage->id, 'status' => $status, 'won_at' => $status === DealStatus::Won ? $now : null, 'lost_at' => $status === DealStatus::Lost ? $now : null, 'lost_reason' => $status === DealStatus::Lost ? ($lostReason ?? $deal->lost_reason) : null, 'last_activity_at' => $now])->save();
            $type = match ($status) {
                DealStatus::Won => CustomerActivityType::DealWon,
                DealStatus::Lost => CustomerActivityType::DealLost,
                DealStatus::Open => CustomerActivityType::DealStageChanged,
            };
            $title = match ($status) {
                DealStatus::Won => 'Deal marked won',
                DealStatus::Lost => 'Deal marked lost',
                DealStatus::Open => 'Deal stage changed',
            };
            $this->record($team, $deal, $type, $title, $actor, $stage->name);

            return $deal->fresh(['customer', 'pipeline', 'stage', 'owner']) ?? $deal;
        });
    }

    private function record(Team $team, Deal $deal, CustomerActivityType $type, string $title, User $actor, ?string $description = null): void
    {
        if ($deal->customer_id === null) {
            return;
        }
        $customer = $team->customers()->whereKey($deal->customer_id)->firstOrFail();
        $this->activities->record($team, $customer, $type, $title, $description, $actor, $deal, route('deals.show', [$team->slug, $deal->id]));
    }

    /** @param array<string, mixed> $filters */
    private function filteredQuery(Team $team, array $filters): Builder
    {
        $query = $team->deals()->with(['customer:id,display_name,email', 'owner:id,name', 'pipeline:id,name', 'stage:id,name,sort_order,semantic_type'])->getQuery();
        if (! empty($filters['pipeline_id'])) {
            $query->where('pipeline_id', (int) $filters['pipeline_id']);
        }
        if (! empty($filters['stage_id'])) {
            $query->where('stage_id', (int) $filters['stage_id']);
        }
        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['owner_user_id'])) {
            $query->where('owner_user_id', (int) $filters['owner_user_id']);
        }
        if (! empty($filters['search'])) {
            $search = '%'.Str::lower(Str::limit((string) $filters['search'], 120, '')).'%';
            $query->where(function (Builder $builder) use ($search): void {
                $builder->whereRaw('LOWER(title) LIKE ?', [$search])->orWhereHas('customer', function (Builder $customer) use ($search): void {
                    $customer->whereRaw("LOWER(COALESCE(display_name, '')) LIKE ?", [$search])->orWhereRaw("LOWER(COALESCE(email, '')) LIKE ?", [$search]);
                });
            });
        }
        if (($filters['expected_close'] ?? null) === 'overdue') {
            $query->where('status', DealStatus::Open)->whereNotNull('expected_close_date')->whereDate('expected_close_date', '<', today());
        } elseif (($filters['expected_close'] ?? null) === '30d') {
            $query->where('status', DealStatus::Open)->whereBetween('expected_close_date', [today(), today()->addDays(30)]);
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function metrics(Builder $query): array
    {
        $summary = (clone $query)->selectRaw("COUNT(*) FILTER (WHERE status = 'open') AS open_count, COUNT(*) FILTER (WHERE status = 'won') AS won_count, COUNT(*) FILTER (WHERE status = 'lost') AS lost_count, COUNT(*) FILTER (WHERE status = 'open' AND expected_close_date < CURRENT_DATE) AS overdue_count, COUNT(*) FILTER (WHERE status = 'open' AND expected_close_date BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '30 days') AS close_soon_count")->first();
        $totals = (clone $query)->whereNotNull('value_amount')->select('currency')->selectRaw("SUM(CASE WHEN status = 'open' THEN value_amount ELSE 0 END) AS open_value, SUM(CASE WHEN status = 'won' THEN value_amount ELSE 0 END) AS won_value")->groupBy('currency')->get()->map(fn ($row): array => ['currency' => $row->currency, 'openValue' => number_format((float) $row->open_value, 2, '.', ''), 'wonValue' => number_format((float) $row->won_value, 2, '.', '')])->values()->all();
        $won = (int) ($summary?->won_count ?? 0);
        $lost = (int) ($summary?->lost_count ?? 0);

        return ['openCount' => (int) ($summary?->open_count ?? 0), 'wonCount' => $won, 'lostCount' => $lost, 'overdueCount' => (int) ($summary?->overdue_count ?? 0), 'closeSoonCount' => (int) ($summary?->close_soon_count ?? 0), 'winRate' => $won + $lost === 0 ? null : round($won / ($won + $lost) * 100, 1), 'byCurrency' => $totals];
    }

    private function selectedPipeline(Team $team, mixed $id): ?Pipeline
    {
        return is_numeric($id) ? $team->pipelines()->whereKey((int) $id)->where('is_active', true)->first() : $team->pipelines()->where('is_default', true)->where('is_active', true)->first();
    }

    /** @return array<string, mixed> */
    private function filterPayload(array $filters, ?Pipeline $pipeline): array
    {
        $statuses = array_map(fn (DealStatus $status): string => $status->value, DealStatus::cases());

        return ['pipelineId' => $pipeline?->id, 'stageId' => is_numeric($filters['stage_id'] ?? null) ? (int) $filters['stage_id'] : null, 'status' => in_array($filters['status'] ?? 'all', array_merge(['all'], $statuses), true) ? ($filters['status'] ?? 'all') : 'all', 'ownerUserId' => is_numeric($filters['owner_user_id'] ?? null) ? (int) $filters['owner_user_id'] : null, 'search' => $filters['search'] ?? null, 'expectedClose' => $filters['expected_close'] ?? null];
    }

    /** @return array<string, mixed> */
    private function pipelineOption(Pipeline $pipeline): array
    {
        return ['id' => $pipeline->id, 'name' => $pipeline->name, 'isDefault' => $pipeline->is_default, 'stages' => $pipeline->stages->map(fn (PipelineStage $stage): array => ['id' => $stage->id, 'name' => $stage->name, 'sortOrder' => $stage->sort_order, 'semanticType' => $stage->semantic_type->value, 'probability' => $this->decimal($stage->probability)])->values()->all()];
    }

    /** @return list<array<string, mixed>> */
    private function board(Builder $query, $stages): array
    {
        $deals = $query->get()->map(fn (Deal $deal): array => $this->listItem($deal));

        return $stages->map(fn (PipelineStage $stage): array => ['stage' => ['id' => $stage->id, 'name' => $stage->name, 'semanticType' => $stage->semantic_type->value, 'sortOrder' => $stage->sort_order], 'deals' => $deals->where('stageId', $stage->id)->values()->all()])->values()->all();
    }

    /** @return array<string, mixed> */
    private function listItem(Deal $deal): array
    {
        return ['id' => $deal->id, 'title' => $deal->title, 'customer' => $deal->customer ? ['id' => $deal->customer->id, 'name' => $deal->customer->name, 'email' => $deal->customer->email] : null, 'pipeline' => ['id' => $deal->pipeline->id, 'name' => $deal->pipeline->name], 'stage' => ['id' => $deal->stage->id, 'name' => $deal->stage->name, 'semanticType' => $deal->stage->semantic_type->value], 'stageId' => $deal->stage_id, 'status' => $deal->status->value, 'valueAmount' => $deal->value_amount !== null ? (string) $deal->value_amount : null, 'currency' => $deal->currency, 'owner' => $deal->owner?->only(['id', 'name']), 'expectedCloseDate' => $deal->expected_close_date?->toDateString(), 'overdue' => $deal->status === DealStatus::Open && $deal->expected_close_date?->isPast(), 'updatedAt' => $deal->updated_at?->toAtomString()];
    }

    /** @param list<array<string, mixed>> $activities */
    private function detailItem(Deal $deal, array $activities): array
    {
        return [...$this->listItem($deal), 'description' => $deal->description, 'lead' => $deal->lead ? ['id' => $deal->lead->id, 'reference' => $deal->lead->public_id, 'name' => $deal->lead->name] : null, 'probability' => $this->decimal($deal->probability), 'lostReason' => $deal->lost_reason, 'wonAt' => $deal->won_at?->toAtomString(), 'lostAt' => $deal->lost_at?->toAtomString(), 'activities' => $activities];
    }

    private function ownerId(Team $team, mixed $ownerId): ?int
    {
        if ($ownerId === null || $ownerId === '') {
            return null;
        }
        $ownerId = (int) $ownerId;
        if (! $team->members()->whereKey($ownerId)->exists()) {
            throw ValidationException::withMessages(['owner_user_id' => 'The selected owner must belong to this Team.']);
        }

        return $ownerId;
    }

    private function nullableText(mixed $value, int $limit): ?string
    {
        return is_string($value) && trim($value) !== '' ? Str::limit(trim($value), $limit, '') : null;
    }

    private function decimal(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }
}
