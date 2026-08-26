<?php

namespace App\Services\Improvements;

use App\Enums\ApiOperationMode;
use App\Enums\ToolRunStatus;
use App\Models\ApiOperation;
use App\Models\Bot;
use App\Models\BotDataset;
use App\Models\Dataset;
use App\Models\KnowledgeGap;
use App\Models\SearchRun;
use App\Models\Team;
use App\Models\ToolRun;
use App\Services\Actions\ActionPresentationService;
use App\Services\DataHealth\DataHealthService;
use App\Services\Integrations\IntegrationHealthService;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Str;

final class ImprovementCenterService
{
    private const MAX_OPPORTUNITIES = 100;

    /**
     * @var array<string, int>
     */
    private const RANGE_DAYS = [
        'today' => 1,
        '7d' => 7,
        '30d' => 30,
        '90d' => 90,
    ];

    /**
     * @var array<string, string>
     */
    private const TYPE_LABELS = [
        'customer_questions' => 'Customer questions',
        'search' => 'Search',
        'data' => 'Data',
        'integrations' => 'Integrations',
        'actions' => 'Actions',
        'configuration' => 'Configuration',
    ];

    /**
     * @var array<string, string>
     */
    private const PRIORITY_LABELS = [
        'high' => 'High priority',
        'medium' => 'Medium priority',
        'low' => 'Recommendation',
    ];

    public function __construct(
        private readonly DataHealthService $dataHealth,
        private readonly IntegrationHealthService $integrationHealth,
        private readonly ActionPresentationService $actionPresentation,
    ) {}

    /**
     * Build deterministic, privacy-safe improvement opportunities for one team.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function index(Team $team, array $filters): array
    {
        [$range, $start, $end] = $this->range($filters['range'] ?? '30d');
        $bots = $team->bots()
            ->select(['id', 'team_id', 'name', 'slug'])
            ->orderBy('name')
            ->get();
        $selectedBot = $this->resolveBot($bots, $filters['bot'] ?? null);
        $botIds = $this->botIds($bots, $selectedBot);
        $type = $this->type($filters['type'] ?? 'all');
        $priority = $this->priority($filters['priority'] ?? 'all');
        $opportunities = [];

        $opportunities = array_merge(
            $opportunities,
            $this->knowledgeGapOpportunities($team, $bots, $botIds, $start, $end),
            $this->zeroResultOpportunities($team, $bots, $botIds, $start, $end),
        );

        $integrationPayload = $this->integrationHealth->index($team, [
            'range' => $range,
            'health' => 'all',
        ]);
        $integrationResults = $this->integrationOpportunities($team, $bots, $selectedBot, $integrationPayload);
        $opportunities = array_merge($opportunities, array_column($integrationResults, 'opportunity'));

        $errorSourceIds = array_values(array_map(
            fn (array $opportunity): int => (int) $opportunity['sourceId'],
            array_filter($integrationResults, fn (array $opportunity): bool => $opportunity['isCurrentError']),
        ));
        $opportunities = array_merge(
            $opportunities,
            $this->dataHealthOpportunities($team, $bots, $selectedBot, $range),
            $this->actionFailureOpportunities($team, $bots, $botIds, $start, $end, $errorSourceIds),
        );

        $visible = collect($opportunities)
            ->filter(fn (ImprovementOpportunity $opportunity): bool => $type === 'all' || $opportunity->category === $type)
            ->filter(fn (ImprovementOpportunity $opportunity): bool => $priority === 'all' || $opportunity->priority === $priority)
            ->sort(fn (ImprovementOpportunity $left, ImprovementOpportunity $right): int => $this->compare($left, $right))
            ->values();

        return [
            'filters' => [
                'bot' => $selectedBot?->slug,
                'range' => $range,
                'type' => $type,
                'priority' => $priority,
            ],
            'botOptions' => $bots->map(fn (Bot $bot): array => [
                'id' => $bot->id,
                'name' => $bot->name,
                'slug' => $bot->slug,
            ])->values()->all(),
            'typeOptions' => array_map(
                fn (string $label, string $key): array => ['key' => $key, 'label' => $label],
                self::TYPE_LABELS,
                array_keys(self::TYPE_LABELS),
            ),
            'priorityOptions' => array_map(
                fn (string $label, string $key): array => ['key' => $key, 'label' => $label],
                self::PRIORITY_LABELS,
                array_keys(self::PRIORITY_LABELS),
            ),
            'summary' => $this->summary($visible),
            'opportunities' => $visible
                ->take(self::MAX_OPPORTUNITIES)
                ->map(fn (ImprovementOpportunity $opportunity): array => $opportunity->toArray())
                ->all(),
            'total' => $visible->count(),
        ];
    }

    /**
     * @param  Collection<int, Bot>  $bots
     * @param  list<int>  $botIds
     * @return array<int, ImprovementOpportunity>
     */
    private function knowledgeGapOpportunities(Team $team, Collection $bots, array $botIds, CarbonImmutable $start, CarbonImmutable $end): array
    {
        if ($botIds === []) {
            return [];
        }

        $rows = KnowledgeGap::query()
            ->where('team_id', $team->id)
            ->whereIn('bot_id', $botIds)
            ->where('status', 'open')
            ->whereBetween('created_at', [$start, $end])
            ->select(['bot_id', 'group_reference', 'normalized_question'])
            ->selectRaw('COUNT(*) AS occurrence_count')
            ->selectRaw('MAX(created_at) AS last_asked_at')
            ->groupBy(['bot_id', 'group_reference', 'normalized_question'])
            ->orderByDesc('occurrence_count')
            ->orderByDesc('last_asked_at')
            ->limit(self::MAX_OPPORTUNITIES)
            ->get();

        return $rows->map(function (KnowledgeGap $gap) use ($team, $bots): ImprovementOpportunity {
            $count = (int) $gap->getAttribute('occurrence_count');
            $bot = $bots->firstWhere('id', (int) $gap->bot_id);
            $question = Str::limit((string) $gap->normalized_question, 500, '');
            $priority = $count >= 3 ? 'high' : ($count >= 2 ? 'medium' : 'low');

            return $this->opportunity(
                type: 'knowledge_gap',
                category: 'customer_questions',
                priority: $priority,
                title: 'Customers repeatedly ask about '.$this->titleCaseQuestion($question),
                description: $question,
                recommendation: 'Add or update knowledge for this question.',
                bot: $this->botPayload($bot),
                evidence: [
                    ['label' => 'Asked', 'value' => $count.' '.Str::plural('time', $count)],
                    ['label' => 'Last asked', 'value' => $this->displayDate($gap->getAttribute('last_asked_at'))],
                ],
                destination: [
                    'label' => 'Review knowledge gap',
                    'url' => route('knowledge-gaps.index', [
                        'current_team' => $team->slug,
                        'bot' => $bot?->slug,
                        'status' => 'open',
                        'search' => $question,
                    ]),
                ],
                lastSeenAt: $this->date($gap->getAttribute('last_asked_at')),
                sortRank: 2,
                sortCount: $count,
            );
        })->all();
    }

    /**
     * Zero-result searches are only shown when their message is not already
     * represented by any KnowledgeGap row in the same team.
     *
     * @param  Collection<int, Bot>  $bots
     * @param  list<int>  $botIds
     * @return array<int, ImprovementOpportunity>
     */
    private function zeroResultOpportunities(Team $team, Collection $bots, array $botIds, CarbonImmutable $start, CarbonImmutable $end): array
    {
        if ($botIds === []) {
            return [];
        }

        $knownGapMessages = KnowledgeGap::query()
            ->where('team_id', $team->id)
            ->whereNotNull('message_id')
            ->select('message_id');
        $rows = SearchRun::query()
            ->whereIn('bot_id', $botIds)
            ->where('status', 'completed')
            ->where('result_count', 0)
            ->whereBetween('created_at', [$start, $end])
            ->where(function (Builder $query) use ($knownGapMessages): void {
                $query->whereNull('message_id')->orWhereNotIn('message_id', $knownGapMessages);
            })
            ->select(['bot_id', 'dataset_id'])
            ->selectRaw('COUNT(*) AS zero_result_count')
            ->selectRaw('MAX(created_at) AS last_seen_at')
            ->groupBy(['bot_id', 'dataset_id'])
            ->orderByDesc('zero_result_count')
            ->orderByDesc('last_seen_at')
            ->limit(self::MAX_OPPORTUNITIES)
            ->get();
        $datasets = Dataset::query()
            ->where('team_id', $team->id)
            ->whereIn('id', $rows->pluck('dataset_id')->filter()->all())
            ->select(['id', 'name', 'slug'])
            ->get()
            ->keyBy('id');

        return $rows->map(function (SearchRun $run) use ($team, $bots, $datasets): ImprovementOpportunity {
            $count = (int) $run->getAttribute('zero_result_count');
            $bot = $bots->firstWhere('id', (int) $run->bot_id);
            $dataset = $datasets->get($run->dataset_id);
            $target = $dataset === null ? 'catalog' : $dataset->name;

            return $this->opportunity(
                type: 'zero_result_search',
                category: 'search',
                priority: $count >= 3 ? 'medium' : 'low',
                title: $target.' searches returned no results',
                description: 'Completed searches did not find a matching result.',
                recommendation: 'Review catalog coverage, searchable fields, or customer terminology.',
                bot: $this->botPayload($bot),
                evidence: [
                    ['label' => 'Zero-result searches', 'value' => (string) $count],
                    ['label' => 'Last seen', 'value' => $this->displayDate($run->getAttribute('last_seen_at'))],
                ],
                destination: [
                    'label' => 'Review search activity',
                    'url' => route('analytics.index', [
                        'current_team' => $team->slug,
                        'bot' => $bot?->slug,
                        'range' => '30d',
                    ]),
                ],
                lastSeenAt: $this->date($run->getAttribute('last_seen_at')),
                sortRank: 2,
                sortCount: $count,
            );
        })->all();
    }

    /**
     * @param  Collection<int, Bot>  $bots
     * @param  array<string, mixed>  $integrationPayload
     * @return list<array<string, mixed>>
     */
    private function integrationOpportunities(Team $team, Collection $bots, ?Bot $selectedBot, array $integrationPayload): array
    {
        $opportunities = [];

        foreach ($integrationPayload['items'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $health = (string) ($item['health'] ?? '');
            $failureCount = (int) ($item['recentFailureCount'] ?? 0);
            $isCurrentError = $health === 'error';

            if (! $isCurrentError && $failureCount === 0) {
                continue;
            }

            $itemBots = is_array($item['bots'] ?? null) ? $item['bots'] : [];
            $bot = $this->integrationBot($bots, $selectedBot, $itemBots);

            if ($selectedBot instanceof Bot && $bot === null) {
                continue;
            }

            $source = is_array($item['source'] ?? null) ? $item['source'] : [];
            $sourceId = (int) ($item['id'] ?? 0);
            $sourceName = (string) ($item['name'] ?? 'Integration');
            $lastFailure = $item['lastFailureAt'] ?? null;
            $opportunities[] = [
                'opportunity' => $this->opportunity(
                    type: 'integration_failure',
                    category: 'integrations',
                    priority: $isCurrentError ? 'high' : 'medium',
                    title: $sourceName.' integration is '.($isCurrentError ? 'failing' : 'unstable'),
                    description: $isCurrentError
                        ? 'The integration is currently in an error state.'
                        : 'The integration has reported recent failures.',
                    recommendation: 'Review the integration configuration and recent failures.',
                    bot: $this->botPayload($bot),
                    evidence: array_values(array_filter([
                        $failureCount > 0 ? ['label' => 'Recent failures', 'value' => (string) $failureCount] : null,
                        $lastFailure !== null ? ['label' => 'Last failure', 'value' => $this->displayDate($lastFailure)] : null,
                        ($item['lastFailureLabel'] ?? null) !== null ? ['label' => 'Failure type', 'value' => (string) $item['lastFailureLabel']] : null,
                    ])),
                    destination: [
                        'label' => 'Review integration health',
                        'url' => route('integration-health.show', [
                            'current_team' => $team->slug,
                            'dataSource' => $sourceId,
                        ]),
                    ],
                    lastSeenAt: $this->date($lastFailure),
                    sortRank: 1,
                    sortCount: $failureCount,
                ),
                'sourceId' => (int) ($source['id'] ?? $sourceId),
                'isCurrentError' => $isCurrentError,
            ];
        }

        return $opportunities;
    }

    /**
     * @param  Collection<int, Bot>  $bots
     * @return array<int, ImprovementOpportunity>
     */
    private function dataHealthOpportunities(Team $team, Collection $bots, ?Bot $selectedBot, string $range): array
    {
        $rows = $this->dataHealth->improvementRows($team, $range);
        $datasetIds = array_map(fn (array $row): int => (int) ($row['id'] ?? 0), $rows);
        $datasetBots = $this->datasetBots($datasetIds, $selectedBot);
        $opportunities = [];

        foreach ($rows as $row) {
            $datasetId = (int) ($row['id'] ?? 0);
            $bot = $datasetBots->get($datasetId);

            if ($selectedBot instanceof Bot && $bot === null) {
                continue;
            }

            $issues = is_array($row['issues'] ?? null) ? $row['issues'] : [];
            $issueTypes = array_values(array_filter(array_map(
                fn (mixed $issue): ?string => is_array($issue) && is_string($issue['type'] ?? null) ? $issue['type'] : null,
                $issues,
            )));
            $hasRootError = in_array('dataset_error', $issueTypes, true) || in_array('source_error', $issueTypes, true);

            foreach ($issues as $issue) {
                if (! is_array($issue)) {
                    continue;
                }

                $issueType = (string) ($issue['type'] ?? '');

                if ($issueType === 'source_error' || ($hasRootError && in_array($issueType, ['no_records', 'no_active_records', 'recent_import_failures'], true))) {
                    continue;
                }

                $opportunities[] = $this->dataIssueOpportunity($team, $row, $issue, $bot);
            }
        }

        return $opportunities;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $issue
     */
    private function dataIssueOpportunity(Team $team, array $row, array $issue, ?Bot $bot): ImprovementOpportunity
    {
        $datasetId = (int) ($row['id'] ?? 0);
        $datasetName = (string) ($row['name'] ?? 'Dataset');
        $type = (string) ($issue['type'] ?? 'dataset_quality');
        $field = $issue['field'] ?? null;
        $fieldLabel = is_string($field) && $field !== '' ? $field : null;
        $title = match ($type) {
            'dataset_error' => $datasetName.' dataset is in an error state',
            'no_records' => $datasetName.' dataset has no imported records',
            'no_active_records' => $datasetName.' dataset has no active records',
            'recent_import_failures' => $datasetName.' dataset has recent import failures',
            'field_zero_coverage' => $datasetName.' has no usable values for '.($fieldLabel ?? 'a configured field'),
            default => $datasetName.' needs data-quality attention',
        };
        $priority = in_array($type, ['dataset_error'], true)
            ? 'high'
            : (in_array($type, ['no_active_records', 'recent_import_failures', 'field_zero_coverage'], true) ? 'medium' : 'low');
        $evidence = [['label' => 'Data Health', 'value' => (string) ($issue['message'] ?? 'Quality issue detected.')]];

        if ($fieldLabel !== null) {
            $evidence[] = ['label' => 'Field', 'value' => $fieldLabel];
        }

        return $this->opportunity(
            type: 'dataset_quality',
            category: 'data',
            priority: $priority,
            title: $title,
            description: (string) ($issue['message'] ?? 'Data quality needs attention.'),
            recommendation: $fieldLabel === null
                ? 'Review the '.$datasetName.' dataset.'
                : 'Improve field coverage before relying on this data in responses.',
            bot: $this->botPayload($bot),
            evidence: $evidence,
            destination: [
                'label' => 'Review data health',
                'url' => route('data-health.show', [
                    'current_team' => $team->slug,
                    'dataset' => $datasetId,
                ]),
            ],
            lastSeenAt: $this->date($row['updatedAt'] ?? null),
            sortRank: 4,
            sortCount: 1,
        );
    }

    /**
     * @param  list<int>  $botIds
     * @param  list<int>  $errorSourceIds
     * @param  Collection<int, Bot>  $bots
     * @return array<int, ImprovementOpportunity>
     */
    private function actionFailureOpportunities(Team $team, Collection $bots, array $botIds, CarbonImmutable $start, CarbonImmutable $end, array $errorSourceIds): array
    {
        if ($botIds === []) {
            return [];
        }

        $rows = ToolRun::query()
            ->where('team_id', $team->id)
            ->whereIn('bot_id', $botIds)
            ->where('execution_mode', ApiOperationMode::Write->value)
            ->whereBetween('created_at', [$start, $end])
            ->whereIn('status', [
                ToolRunStatus::Completed->value,
                ToolRunStatus::Failed->value,
                ToolRunStatus::Cancelled->value,
            ])
            ->select(['bot_id', 'tool_name', 'api_operation_id'])
            ->selectRaw('COUNT(*) AS terminal_attempts')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS failure_count', [ToolRunStatus::Failed->value])
            ->selectRaw('MAX(failed_at) AS last_failure_at')
            ->groupBy(['bot_id', 'tool_name', 'api_operation_id'])
            ->havingRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) > 0', [ToolRunStatus::Failed->value])
            ->orderByDesc('failure_count')
            ->limit(self::MAX_OPPORTUNITIES)
            ->get();
        $operationIds = $rows->pluck('api_operation_id')->filter()->all();
        $operations = ApiOperation::query()
            ->whereIn('id', $operationIds)
            ->whereIn('data_source_id', $team->dataSources()->select('id'))
            ->select(['id', 'data_source_id'])
            ->get()
            ->keyBy('id');

        return $rows->map(function (ToolRun $run) use ($team, $bots, $operations, $errorSourceIds): ?ImprovementOpportunity {
            $operation = $operations->get($run->api_operation_id);

            if ($operation !== null && in_array((int) $operation->data_source_id, $errorSourceIds, true)) {
                return null;
            }

            $failures = (int) $run->getAttribute('failure_count');
            $attempts = (int) $run->getAttribute('terminal_attempts');
            $bot = $bots->firstWhere('id', (int) $run->bot_id);
            $label = $this->actionPresentation->label((string) $run->tool_name);

            return $this->opportunity(
                type: 'action_failure',
                category: 'actions',
                priority: $failures >= 3 ? 'high' : 'medium',
                title: $label.' failed '.$failures.' '.Str::plural('time', $failures),
                description: $label.' had '.$failures.' failures in '.$attempts.' terminal attempts.',
                recommendation: 'Review failed actions and provider configuration.',
                bot: $this->botPayload($bot),
                evidence: [
                    ['label' => 'Failures', 'value' => (string) $failures],
                    ['label' => 'Terminal attempts', 'value' => (string) $attempts],
                    ['label' => 'Failure rate', 'value' => round(($failures / max(1, $attempts)) * 100, 1).'%'],
                ],
                destination: [
                    'label' => 'Review action history',
                    'url' => route('actions.index', [
                        'current_team' => $team->slug,
                        'bot' => $bot?->slug,
                        'action' => $run->tool_name,
                        'status' => ToolRunStatus::Failed->value,
                        'range' => '30d',
                    ]),
                ],
                lastSeenAt: $this->date($run->getAttribute('last_failure_at')),
                sortRank: 3,
                sortCount: $failures,
            );
        })->filter()->values()->all();
    }

    /**
     * @param  list<int>  $datasetIds
     * @return SupportCollection<int|string, Bot>
     */
    private function datasetBots(array $datasetIds, ?Bot $selectedBot): SupportCollection
    {
        if ($datasetIds === []) {
            return new SupportCollection;
        }

        $query = BotDataset::query()
            ->whereIn('dataset_id', $datasetIds)
            ->with('bot:id,team_id,name,slug')
            ->orderBy('bot_id');

        if ($selectedBot instanceof Bot) {
            $query->where('bot_id', $selectedBot->id);
        }

        $datasetBots = new SupportCollection;

        foreach ($query->get() as $attachment) {
            if (! $attachment->bot instanceof Bot) {
                continue;
            }

            $datasetBots->put((int) $attachment->dataset_id, $attachment->bot);
        }

        return $datasetBots;
    }

    /**
     * @param  Collection<int, Bot>  $bots
     * @param  array<int, mixed>  $itemBots
     */
    private function integrationBot(Collection $bots, ?Bot $selectedBot, array $itemBots): ?Bot
    {
        if ($selectedBot instanceof Bot) {
            foreach ($itemBots as $itemBot) {
                if (is_array($itemBot) && (int) ($itemBot['id'] ?? 0) === $selectedBot->id) {
                    return $selectedBot;
                }
            }

            return null;
        }

        $first = $itemBots[0] ?? null;

        return is_array($first) ? $bots->firstWhere('id', (int) ($first['id'] ?? 0)) : null;
    }

    /**
     * @param  Collection<int, Bot>  $bots
     * @return list<int>
     */
    private function botIds(Collection $bots, ?Bot $selectedBot): array
    {
        if ($selectedBot instanceof Bot) {
            return [$selectedBot->id];
        }

        $botIds = [];

        foreach ($bots as $bot) {
            $botIds[] = (int) $bot->id;
        }

        return $botIds;
    }

    /**
     * @param  Collection<int, Bot>  $bots
     */
    private function resolveBot(Collection $bots, mixed $value): ?Bot
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $bots->first(fn (Bot $bot): bool => $bot->slug === trim($value));
    }

    /**
     * @return array{0: string, 1: CarbonImmutable, 2: CarbonImmutable}
     */
    private function range(mixed $value): array
    {
        $key = is_string($value) && array_key_exists($value, self::RANGE_DAYS) ? $value : '30d';
        $now = CarbonImmutable::now();
        $days = self::RANGE_DAYS[$key];

        return [$key, $now->startOfDay()->subDays($days - 1), $now];
    }

    private function type(mixed $value): string
    {
        return is_string($value) && (array_key_exists($value, self::TYPE_LABELS) || $value === 'all') ? $value : 'all';
    }

    private function priority(mixed $value): string
    {
        return is_string($value) && (array_key_exists($value, self::PRIORITY_LABELS) || $value === 'all') ? $value : 'all';
    }

    /**
     * @param  SupportCollection<int, ImprovementOpportunity>  $opportunities
     * @return array{open: int, highPriority: int, customerQuestions: int, dataIntegrationIssues: int}
     */
    private function summary(SupportCollection $opportunities): array
    {
        return [
            'open' => $opportunities->count(),
            'highPriority' => $opportunities->where('priority', 'high')->count(),
            'customerQuestions' => $opportunities->where('category', 'customer_questions')->count(),
            'dataIntegrationIssues' => $opportunities->filter(fn (ImprovementOpportunity $opportunity): bool => in_array($opportunity->category, ['data', 'integrations'], true))->count(),
        ];
    }

    private function compare(ImprovementOpportunity $left, ImprovementOpportunity $right): int
    {
        return [$left->sortRank, -$left->sortCount, -$this->timestamp($left->lastSeenAt)] <=> [$right->sortRank, -$right->sortCount, -$this->timestamp($right->lastSeenAt)];
    }

    private function timestamp(?DateTimeInterface $value): int
    {
        return $value?->getTimestamp() ?? 0;
    }

    /**
     * @param  array{id: int, name: string, slug: string}|null  $bot
     * @param  list<array{label: string, value: string}>  $evidence
     * @param  array{label: string, url: string}  $destination
     */
    private function opportunity(
        string $type,
        string $category,
        string $priority,
        string $title,
        string $description,
        string $recommendation,
        ?array $bot,
        array $evidence,
        array $destination,
        ?DateTimeInterface $lastSeenAt,
        int $sortRank,
        int $sortCount,
    ): ImprovementOpportunity {
        return new ImprovementOpportunity(
            type: $type,
            category: $category,
            priority: $priority,
            title: $title,
            description: $description,
            recommendation: $recommendation,
            bot: $bot,
            evidence: $evidence,
            destination: $destination,
            lastSeenAt: $lastSeenAt,
            sortRank: $sortRank,
            sortCount: $sortCount,
        );
    }

    /**
     * @return array{id: int, name: string, slug: string}|null
     */
    private function botPayload(?Bot $bot): ?array
    {
        return $bot === null ? null : [
            'id' => $bot->id,
            'name' => $bot->name,
            'slug' => $bot->slug,
        ];
    }

    private function date(mixed $value): ?DateTimeInterface
    {
        if ($value instanceof DateTimeInterface) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function displayDate(mixed $value): string
    {
        $date = $this->date($value);

        return $date?->format(DATE_ATOM) ?? 'Unknown';
    }

    private function titleCaseQuestion(string $question): string
    {
        return Str::limit('"'.trim($question).'"', 180, '');
    }
}
