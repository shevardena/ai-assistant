<?php

namespace App\Services\Leads;

use App\Enums\LeadStatus;
use App\Enums\ToolRunStatus;
use App\Models\Bot;
use App\Models\BotApiOperation;
use App\Models\Lead;
use App\Models\Team;
use App\Models\ToolRun;
use App\Services\Customers\CustomerIdentityResolutionService;
use App\Services\Tasks\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

final class LeadService
{
    public function __construct(
        private readonly CustomerIdentityResolutionService $customers,
        private readonly TaskService $tasks,
    ) {}

    private const PER_PAGE = 25;

    /**
     * Persist the privacy-safe lead projection after a completed capture action.
     *
     * The unique tool_run_id constraint makes repeated completion callbacks
     * idempotent without deduplicating legitimate separate captures.
     */
    public function createFromCompletedRun(ToolRun $run): ?Lead
    {
        if ($run->tool_name !== 'capture_lead' || $this->runStatus($run) !== ToolRunStatus::Completed) {
            return null;
        }

        $run->loadMissing([
            'bot:id,team_id,name,slug',
            'bot.team:id',
            'conversation:id,public_id,visitor_id,metadata',
        ]);

        $bot = $run->bot;

        if (! $bot instanceof Bot || data_get($run->conversation?->metadata, 'source') === 'dashboard_preview') {
            return null;
        }

        $safeArguments = $this->safeArray($run->getAttribute('safe_arguments'));
        $safeResult = $this->safeArray($run->getAttribute('safe_result'));
        $modelInputs = $this->modelInputs($run, $safeArguments);
        $interestSummary = $this->interestSummary($modelInputs);
        $providerReference = $this->safeText(
            $safeResult['lead_reference'] ?? $safeResult['reference'] ?? null,
            255,
        );
        $customerResolution = $this->customers->resolve($bot->team, [
            'name' => $modelInputs['name'] ?? null,
            'email' => $modelInputs['email'] ?? null,
            'phone' => $modelInputs['phone'] ?? null,
            'source' => $this->source($run),
        ], allowNameOnly: true);

        if ($customerResolution->customer !== null) {
            $customerResolution->customer->forceFill(['last_activity_at' => now()])->saveQuietly();
            $run->conversation?->update(['customer_id' => $customerResolution->customer->id]);
        }

        $attributes = [
            'public_id' => (string) Str::uuid(),
            'team_id' => $bot->team_id,
            'bot_id' => $bot->id,
            'conversation_id' => $run->conversation_id,
            'customer_id' => $customerResolution->customer?->id,
            'tool_run_id' => $run->id,
            'status' => LeadStatus::New->value,
            'name' => $this->safeText($modelInputs['name'] ?? null, 255),
            'email' => $this->safeText($modelInputs['email'] ?? null, 320),
            'phone' => $this->safeText($modelInputs['phone'] ?? null, 64),
            'interest_summary' => $interestSummary,
            'source' => $this->source($run),
            'provider_reference' => $providerReference,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        Lead::query()->insertOrIgnore([$attributes]);

        return Lead::query()->where('tool_run_id', $run->id)->first();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function index(Team $team, array $filters): array
    {
        $bots = $team->bots()
            ->select(['id', 'name', 'slug'])
            ->orderBy('name')
            ->get();
        $botFilter = $this->stringFilter($filters['bot'] ?? null);
        $selectedBot = $this->resolveBot($bots, $botFilter);
        $range = $this->range($filters['range'] ?? '30d');
        $status = $this->status($filters['status'] ?? 'all');
        $search = Str::limit(trim((string) ($filters['search'] ?? '')), 120, '');
        $baseQuery = $this->baseQuery($team, $selectedBot, $botFilter, $range, $search);
        $listQuery = clone $baseQuery;

        if ($status !== 'all') {
            $listQuery->where('status', $status);
        }

        $leads = $listQuery
            ->select([
                'id',
                'public_id',
                'bot_id',
                'conversation_id',
                'customer_id',
                'status',
                'name',
                'email',
                'phone',
                'source',
                'created_at',
            ])
            ->with(['bot:id,name,slug', 'customer:id,display_name,email,phone'])
            ->latest('created_at')
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
        $summary = $this->summary($baseQuery);

        return [
            'filters' => [
                'bot' => $selectedBot?->slug,
                'range' => $range['key'],
                'status' => $status,
                'search' => $search !== '' ? $search : null,
            ],
            'botOptions' => $bots->map(fn (Bot $bot): array => [
                'id' => $bot->id,
                'name' => $bot->name,
                'slug' => $bot->slug,
            ])->values()->all(),
            'statusOptions' => array_map(fn (LeadStatus $leadStatus): array => [
                'key' => $leadStatus->value,
                'label' => $this->statusLabel($leadStatus),
            ], LeadStatus::cases()),
            'summary' => $summary,
            'leads' => $leads->through(fn (Lead $lead): array => $this->listItem($lead)),
        ];
    }

    /**
     * @return array{lead: array<string, mixed>, statusOptions: list<array{key: string, label: string}>}
     */
    public function detail(Team $team, Lead $lead): array
    {
        $scopedLead = $team->leads()
            ->whereKey($lead->getKey())
            ->with([
                'bot:id,name,slug',
                'conversation:id,public_id',
                'customer:id,display_name,email,phone',
                'toolRun:id,action_reference,status,completed_at',
                'deals:id,lead_id,title,status,value_amount,currency',
            ])
            ->firstOrFail();

        return [
            'lead' => $this->detailItem($scopedLead),
            'statusOptions' => array_map(fn (LeadStatus $status): array => [
                'key' => $status->value,
                'label' => $this->statusLabel($status),
            ], LeadStatus::cases()),
            'tasks' => $this->tasks->forLead($team, $scopedLead),
        ];
    }

    public function updateStatus(Team $team, Lead $lead, LeadStatus $status): Lead
    {
        $scopedLead = $team->leads()
            ->whereKey($lead->getKey())
            ->firstOrFail();

        $scopedLead->update(['status' => $status->value]);

        return $scopedLead->fresh() ?? $scopedLead;
    }

    private function runStatus(ToolRun $run): ToolRunStatus
    {
        $status = $run->getAttribute('status');

        return $status instanceof ToolRunStatus
            ? $status
            : ToolRunStatus::from((string) $status);
    }

    /**
     * @param  array<string, mixed>  $safeArguments
     * @return array<string, string>
     */
    private function modelInputs(ToolRun $run, array $safeArguments): array
    {
        $attachment = BotApiOperation::query()
            ->where('bot_id', $run->bot_id)
            ->where('api_operation_id', $run->api_operation_id)
            ->where('tool_name', 'capture_lead')
            ->first();
        $settings = $attachment?->getAttribute('settings');
        $mappings = is_array($settings) && is_array($settings['input_mapping'] ?? null)
            ? $settings['input_mapping']
            : [];
        $inputs = [];

        foreach ($mappings as $modelInput => $mapping) {
            if (! is_string($modelInput) || ! is_array($mapping)) {
                continue;
            }

            $source = $mapping['source'] ?? null;

            if ($source !== 'model_input' && ! ($modelInput === 'product_reference' && $source === 'dataset_field')) {
                continue;
            }

            $operationArgument = $mapping['operation_argument'] ?? $mapping['argument'] ?? null;

            if (! is_string($operationArgument) || ! array_key_exists($operationArgument, $safeArguments)) {
                continue;
            }

            $value = $safeArguments[$operationArgument];

            if (is_string($value) && trim($value) !== '') {
                $inputs[$modelInput] = trim($value);
            }
        }

        return $inputs;
    }

    /**
     * @param  array<string, string>  $modelInputs
     */
    private function interestSummary(array $modelInputs): ?string
    {
        $message = $this->safeText($modelInputs['message'] ?? null, 2000);
        $productReference = $this->safeText($modelInputs['product_reference'] ?? null, 255);

        if ($message === null) {
            return $productReference === null ? null : 'Product: '.$productReference;
        }

        if ($productReference === null) {
            return $message;
        }

        return Str::limit($message.' Product: '.$productReference, 2000, '');
    }

    private function source(ToolRun $run): string
    {
        if ($run->visitor_id !== null || data_get($run->conversation?->metadata, 'source') === 'widget') {
            return 'widget';
        }

        return $run->conversation_id === null ? 'api' : 'conversation';
    }

    /**
     * @return array{key: string, start: CarbonImmutable|null, end: CarbonImmutable|null}
     */
    private function range(mixed $value): array
    {
        $key = is_string($value) && in_array($value, ['all', 'today', '7d', '30d', '90d'], true)
            ? $value
            : '30d';

        if ($key === 'all') {
            return ['key' => $key, 'start' => null, 'end' => null];
        }

        $now = CarbonImmutable::now();
        $days = ['today' => 1, '7d' => 7, '30d' => 30, '90d' => 90][$key];

        return [
            'key' => $key,
            'start' => $now->startOfDay()->subDays($days - 1),
            'end' => $now,
        ];
    }

    private function status(mixed $value): string
    {
        if ($value === 'all') {
            return 'all';
        }

        return is_string($value) && LeadStatus::tryFrom($value) instanceof LeadStatus
            ? $value
            : 'all';
    }

    private function stringFilter(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  Collection<int, Bot>  $bots
     */
    private function resolveBot(Collection $bots, ?string $filter): ?Bot
    {
        return $filter === null
            ? null
            : $bots->first(fn (Bot $bot): bool => $bot->slug === $filter);
    }

    /**
     * @param  array{key: string, start: CarbonImmutable|null, end: CarbonImmutable|null}  $range
     * @return Builder<Lead>
     */
    private function baseQuery(
        Team $team,
        ?Bot $selectedBot,
        ?string $botFilter,
        array $range,
        string $search,
    ): Builder {
        $query = $team->leads()->getQuery();

        if ($botFilter !== null) {
            if ($selectedBot instanceof Bot) {
                $query->where('bot_id', $selectedBot->id);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        if ($range['start'] !== null && $range['end'] !== null) {
            $query->whereBetween('created_at', [$range['start'], $range['end']]);
        }

        if ($search !== '') {
            $pattern = '%'.mb_strtolower($search).'%';
            $query->where(function (Builder $query) use ($pattern): void {
                $query
                    ->whereRaw("LOWER(COALESCE(name, '')) LIKE ?", [$pattern])
                    ->orWhereRaw("LOWER(COALESCE(email, '')) LIKE ?", [$pattern])
                    ->orWhereRaw("LOWER(COALESCE(phone, '')) LIKE ?", [$pattern])
                    ->orWhereRaw("LOWER(COALESCE(interest_summary, '')) LIKE ?", [$pattern])
                    ->orWhereRaw("LOWER(COALESCE(provider_reference, '')) LIKE ?", [$pattern]);
            });
        }

        return $query;
    }

    /**
     * @param  Builder<Lead>  $query
     * @return array{total: int, new: int, contacted: int, qualified: int, won: int, lost: int}
     */
    private function summary(Builder $query): array
    {
        $row = (clone $query)
            ->toBase()
            ->selectRaw("COUNT(*) AS total, SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) AS new, SUM(CASE WHEN status = 'contacted' THEN 1 ELSE 0 END) AS contacted, SUM(CASE WHEN status = 'qualified' THEN 1 ELSE 0 END) AS qualified, SUM(CASE WHEN status = 'won' THEN 1 ELSE 0 END) AS won, SUM(CASE WHEN status = 'lost' THEN 1 ELSE 0 END) AS lost")
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'new' => (int) ($row->new ?? 0),
            'contacted' => (int) ($row->contacted ?? 0),
            'qualified' => (int) ($row->qualified ?? 0),
            'won' => (int) ($row->won ?? 0),
            'lost' => (int) ($row->lost ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function listItem(Lead $lead): array
    {
        return [
            'id' => $lead->id,
            'reference' => $lead->public_id,
            'name' => $lead->name ?? $lead->email ?? 'Lead',
            'email' => $lead->email,
            'phone' => $lead->phone,
            'status' => $this->statusValue($lead),
            'statusLabel' => $this->statusLabel($this->statusEnum($lead)),
            'source' => $lead->source,
            'sourceLabel' => $this->sourceLabel($lead->source),
            'capturedAt' => $lead->created_at?->toAtomString(),
            'bot' => $lead->bot === null ? null : [
                'id' => $lead->bot->id,
                'name' => $lead->bot->name,
                'slug' => $lead->bot->slug,
            ],
            'customer' => $lead->customer === null ? null : ['id' => $lead->customer->id, 'name' => $lead->customer->name],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detailItem(Lead $lead): array
    {
        return [
            ...$this->listItem($lead),
            'interestSummary' => $lead->interest_summary,
            'providerReference' => $lead->provider_reference,
            'conversation' => $lead->conversation === null ? null : [
                'reference' => $lead->conversation->public_id,
            ],
            'customer' => $lead->customer === null ? null : ['id' => $lead->customer->id, 'name' => $lead->customer->name],
            'action' => $lead->toolRun === null ? null : [
                'reference' => $lead->toolRun->action_reference,
            ],
            'deals' => $lead->deals->map(fn ($deal): array => ['id' => $deal->id, 'title' => $deal->title, 'status' => $deal->status->value, 'valueAmount' => $deal->value_amount === null ? null : (string) $deal->value_amount, 'currency' => $deal->currency])->values()->all(),
        ];
    }

    private function statusEnum(Lead $lead): LeadStatus
    {
        $status = $lead->getAttribute('status');

        return $status instanceof LeadStatus
            ? $status
            : LeadStatus::from((string) $status);
    }

    private function statusValue(Lead $lead): string
    {
        return $this->statusEnum($lead)->value;
    }

    private function statusLabel(LeadStatus $status): string
    {
        return ucfirst($status->value);
    }

    private function sourceLabel(string $source): string
    {
        return match ($source) {
            'widget' => 'Website widget',
            'preview' => 'Dashboard preview',
            'api' => 'API',
            default => 'Conversation',
        };
    }

    private function safeText(mixed $value, int $limit): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : Str::limit($value, $limit, '');
    }

    /**
     * @return array<string, mixed>
     */
    private function safeArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_filter($value, static fn (mixed $item, mixed $key): bool => is_string($key), ARRAY_FILTER_USE_BOTH);
    }
}
