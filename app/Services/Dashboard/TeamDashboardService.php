<?php

namespace App\Services\Dashboard;

use App\Enums\BotStatus;
use App\Enums\ConversationHandoffStatus;
use App\Enums\ConversationStatus;
use App\Enums\RuntimeMode;
use App\Enums\TeamPermission;
use App\Enums\ToolRunStatus;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\SourceRun;
use App\Models\Team;
use App\Models\ToolRun;
use App\Models\User;
use App\Services\Billing\TeamEntitlementService;
use App\Services\Billing\TeamUsageService;
use App\Services\DataHealth\DataHealthService;
use App\Services\Improvements\ImprovementCenterService;
use App\Services\Integrations\IntegrationHealthService;
use App\Services\Onboarding\BusinessTemplateDefinition;
use App\Services\Onboarding\BusinessTemplateRegistry;
use App\Services\Onboarding\OnboardingChecklistService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class TeamDashboardService
{
    /** @var array<string, int> */
    private const RANGE_DAYS = ['today' => 1, '7d' => 7, '30d' => 30];

    public function __construct(
        private readonly TeamUsageService $usage,
        private readonly IntegrationHealthService $integrations,
        private readonly DataHealthService $dataHealth,
        private readonly ImprovementCenterService $improvements,
        private readonly TeamEntitlementService $entitlements,
        private readonly OnboardingChecklistService $checklist,
        private readonly BusinessTemplateRegistry $templates,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Team $team, User $user, string $range = '7d'): array
    {
        [$range, $start, $end, $previousStart, $previousEnd] = $this->period($range);
        $production = $this->productionConversations($team, $start, $end);
        $previousProduction = $this->productionConversationQuery($team, $previousStart, $previousEnd);
        $actions = $this->productionActionQuery($team, $start, $end);
        $previousActions = $this->productionActionQuery($team, $previousStart, $previousEnd);
        $improvement = $this->improvements->index($team, ['range' => $range]);
        $dataHealth = $this->dataHealth->index($team, ['range' => $range]);
        $integrationHealth = $this->integrations->index($team, ['range' => $range]);

        return [
            'range' => $range,
            'team' => ['name' => $team->name, 'slug' => $team->slug],
            'metrics' => [
                'conversations' => $this->metric(
                    $production->count(),
                    $previousProduction->count(),
                    route('conversations.index', $team->slug),
                ),
                'leads' => $this->metric(
                    $this->outcomeCount($team, 'leads', $start, $end),
                    $this->outcomeCount($team, 'leads', $previousStart, $previousEnd),
                    route('leads.index', $team->slug),
                ),
                'successfulActions' => $this->metric(
                    (clone $actions)->where('status', ToolRunStatus::Completed->value)->count(),
                    (clone $previousActions)->where('status', ToolRunStatus::Completed->value)->count(),
                    route('actions.index', $team->slug),
                ),
                'handoffs' => $this->metric(
                    (clone $production)->where('handoff_status', ConversationHandoffStatus::Requested->value)->count(),
                    (clone $previousProduction)->where('handoff_status', ConversationHandoffStatus::Requested->value)->count(),
                    route('conversations.index', [$team->slug, 'handoff' => 'needs_attention']),
                ),
            ],
            'attention' => $this->attention($team, $production, $actions, $start, $end, (array) ($integrationHealth['summary'] ?? [])),
            'health' => $this->health($team, $dataHealth, $integrationHealth),
            'activity' => $this->activity($team, $start, $end),
            'recentConversations' => $this->recentConversations($team, $start, $end),
            'outcomes' => [
                'leads' => $this->outcomeCount($team, 'leads', $start, $end),
                'appointments' => $this->outcomeCount($team, 'appointments', $start, $end),
                'tickets' => $this->outcomeCount($team, 'tickets', $start, $end),
                'completedActions' => (clone $actions)->where('status', ToolRunStatus::Completed->value)->count(),
            ],
            'improvements' => [
                'summary' => $improvement['summary'] ?? [],
                'opportunities' => array_slice((array) ($improvement['opportunities'] ?? []), 0, 5),
                'url' => route('improvements.index', $team->slug),
            ],
            'setup' => $this->setup($team),
            'bots' => $this->botSummary($team),
            'channels' => $this->channelSummary($team),
            'billing' => $user->hasTeamPermission($team, TeamPermission::BillingView)
                ? $this->entitlements->billingSummary($team)->toArray()
                : null,
            'unreadNotifications' => $user->notifications()
                ->where('team_id', $team->id)
                ->whereNull('read_at')
                ->count(),
            'quickActions' => $this->quickActions($team, $user),
        ];
    }

    /** @return array{0: string, 1: CarbonImmutable, 2: CarbonImmutable, 3: CarbonImmutable, 4: CarbonImmutable} */
    private function period(string $value): array
    {
        $range = array_key_exists($value, self::RANGE_DAYS) ? $value : '7d';
        $end = CarbonImmutable::now();
        $start = $end->startOfDay()->subDays(self::RANGE_DAYS[$range] - 1);
        $previousEnd = $start->subSecond();
        $previousStart = $previousEnd->startOfDay()->subDays(self::RANGE_DAYS[$range] - 1);

        return [$range, $start, $end, $previousStart, $previousEnd];
    }

    /** @return Builder<Conversation> */
    private function productionConversationQuery(Team $team, CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return $this->usage->productionConversationQuery($team)
            ->whereBetween('conversations.created_at', [$start, $end]);
    }

    /** @return Collection<int, Conversation> */
    private function productionConversations(Team $team, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return $this->productionConversationQuery($team, $start, $end)->get();
    }

    /** @return Builder<ToolRun> */
    private function productionActionQuery(Team $team, CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return $team->toolRuns()->getQuery()
            ->whereIn('bot_id', $team->bots()->select('id'))
            ->where('runtime_mode', RuntimeMode::Normal->value)
            ->whereBetween('tool_runs.created_at', [$start, $end]);
    }

    /** @return array{value: int, change: float|null, url: string} */
    private function metric(int $value, int $previous, string $url): array
    {
        return [
            'value' => $value,
            'change' => $previous === 0 ? null : round((($value - $previous) / $previous) * 100, 1),
            'url' => $url,
        ];
    }

    /**
     * @param  Collection<int, Conversation>  $production
     * @param  Builder<ToolRun>  $actions
     * @param  array<string, mixed>  $integrationSummary
     * @return list<array{key: string, count: int, url: string}>
     */
    private function attention(Team $team, Collection $production, Builder $actions, CarbonImmutable $start, CarbonImmutable $end, array $integrationSummary): array
    {
        $items = [];
        $this->addAttention($items, 'handoffs', $production->where('handoff_status', ConversationHandoffStatus::Requested->value)->count(), route('conversations.index', [$team->slug, 'handoff' => 'needs_attention']));
        $this->addAttention($items, 'failed_actions', (clone $actions)->where('status', ToolRunStatus::Failed->value)->count(), route('actions.index', [$team->slug, 'status' => ToolRunStatus::Failed->value]));
        $this->addAttention($items, 'failed_imports', $this->failedImportCount($team, $start, $end), route('integration-health.index', $team->slug));
        $this->addAttention($items, 'unassigned', $production->where('conversation_status', ConversationStatus::Open->value)->whereNull('assigned_to_user_id')->count(), route('conversations.index', [$team->slug, 'assignee' => 'unassigned', 'status' => ConversationStatus::Open->value]));
        $this->addAttention($items, 'integration_failures', (int) ($integrationSummary['errors'] ?? 0), route('integration-health.index', $team->slug));

        return $items;
    }

    /** @param list<array{key: string, count: int, url: string}> $items */
    private function addAttention(array &$items, string $key, int $count, string $url): void
    {
        if ($count > 0) {
            $items[] = ['key' => $key, 'count' => $count, 'url' => $url];
        }
    }

    /** @return array{bots: array<string, mixed>, data: array<string, mixed>, integrations: array<string, mixed>, channels: array<string, mixed>} */
    private function health(Team $team, array $dataHealth, array $integrationHealth): array
    {
        $bots = $team->bots();
        $readyBots = $team->bots()->whereIn('status', [BotStatus::Ready->value, BotStatus::Published->value]);
        $dataSummary = (array) ($dataHealth['summary'] ?? []);
        $integrationSummary = (array) ($integrationHealth['summary'] ?? []);

        return [
            'bots' => ['state' => $readyBots->exists() ? 'healthy' : 'warning', 'total' => $bots->count(), 'ready' => $readyBots->count()],
            'data' => ['state' => ((int) ($dataSummary['errors'] ?? 0)) > 0 ? 'error' : (((int) ($dataSummary['warnings'] ?? 0)) > 0 ? 'warning' : 'healthy'), 'healthy' => (int) ($dataSummary['healthy'] ?? 0), 'warnings' => (int) ($dataSummary['warnings'] ?? 0), 'errors' => (int) ($dataSummary['errors'] ?? 0), 'url' => route('data-health.index', $team->slug)],
            'integrations' => ['state' => ((int) ($integrationSummary['errors'] ?? 0)) > 0 ? 'error' : (((int) ($integrationSummary['warnings'] ?? 0)) > 0 ? 'warning' : 'healthy'), 'healthy' => (int) ($integrationSummary['healthy'] ?? 0), 'warnings' => (int) ($integrationSummary['warnings'] ?? 0), 'errors' => (int) ($integrationSummary['errors'] ?? 0), 'url' => route('integration-health.index', $team->slug)],
            'channels' => ['state' => $team->channelConnections()->where('status', 'active')->exists() ? 'healthy' : 'warning', 'active' => $team->channelConnections()->where('status', 'active')->count(), 'total' => $team->channelConnections()->count(), 'url' => $team->bots()->first() instanceof Bot ? route('bots.channels.index', [$team->slug, $team->bots()->first()]) : route('bots.index', $team->slug)],
        ];
    }

    /** @return list<array{date: string, value: int}> */
    private function activity(Team $team, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $counts = $this->productionConversationQuery($team, $start, $end)
            ->selectRaw($this->dateExpression().' AS bucket')
            ->selectRaw('COUNT(*) AS value')
            ->groupByRaw($this->dateExpression())
            ->pluck('value', 'bucket');
        $points = [];

        for ($date = $start->startOfDay(); $date <= $end->startOfDay(); $date = $date->addDay()) {
            $key = $date->toDateString();
            $points[] = ['date' => $key, 'value' => (int) ($counts[$key] ?? 0)];
        }

        return $points;
    }

    private function dateExpression(): string
    {
        return app('db')->connection()->getDriverName() === 'pgsql' ? "DATE_TRUNC('day', conversations.created_at)::date" : 'DATE(conversations.created_at)';
    }

    /** @return list<array<string, mixed>> */
    private function recentConversations(Team $team, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return $this->productionConversationQuery($team, $start, $end)
            ->select(['conversations.id', 'conversations.public_id', 'conversations.bot_id', 'conversations.channel', 'conversations.summary', 'conversations.metadata', 'conversations.conversation_status', 'conversations.handoff_status', 'conversations.assigned_to_user_id', 'conversations.last_message_at', 'conversations.created_at'])
            ->with(['bot:id,name,slug', 'assignedTo:id,name'])
            ->addSelect(['latest_user_message' => Message::query()->select('content')->whereColumn('conversation_id', 'conversations.id')->where('role', 'user')->latest('id')->limit(1)])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn (Conversation $conversation): array => [
                'reference' => $conversation->public_id,
                'channel' => $conversation->channel->value,
                'title' => $this->conversationTitle($conversation),
                'status' => $conversation->conversation_status->value,
                'assignee' => $conversation->assignedTo?->name,
                'lastActivityAt' => ($conversation->last_message_at ?? $conversation->created_at)?->format(DATE_ATOM),
                'url' => route('conversations.show', [$team->slug, $conversation->public_id]),
            ])
            ->values()
            ->all();
    }

    private function conversationTitle(Conversation $conversation): string
    {
        $title = $conversation->summary ?: data_get($conversation->metadata, 'email_subject') ?: ($conversation->latest_user_message ?? null);

        return trim(is_string($title) && $title !== '' ? mb_substr(strip_tags($title), 0, 120) : 'Conversation');
    }

    private function failedImportCount(Team $team, CarbonImmutable $start, CarbonImmutable $end): int
    {
        return SourceRun::query()
            ->whereIn('data_source_id', $team->dataSources()->select('id'))
            ->where('status', 'failed')
            ->whereBetween('finished_at', [$start, $end])
            ->count();
    }

    private function outcomeCount(Team $team, string $type, CarbonImmutable $start, CarbonImmutable $end): int
    {
        $relation = match ($type) {
            'leads' => 'leads', 'appointments' => 'appointments', default => 'supportTickets'
        };

        return $team->{$relation}()
            ->whereIn('bot_id', $team->bots()->select('id'))
            ->whereBetween('created_at', [$start, $end])
            ->whereHas('toolRun', fn (Builder $query): Builder => $query
                ->where('team_id', $team->id)
                ->where('runtime_mode', RuntimeMode::Normal->value))
            ->count();
    }

    /** @return array<string, mixed> */
    private function setup(Team $team): array
    {
        $bot = $team->bots()->with('team')->latest('id')->first();
        $steps = [];

        if ($bot instanceof Bot) {
            $template = $this->templates->find((string) $bot->business_template);
            if ($template instanceof BusinessTemplateDefinition) {
                $steps = $this->checklist->forBot($bot, $template)['steps'];
            }
        }

        if ($steps === []) {
            $steps = [['key' => 'bot', 'label' => 'Create your first Bot', 'completed' => $bot instanceof Bot, 'actionUrl' => route('bots.create', $team->slug)]];
        }

        $productionExists = $this->usage->productionConversationQuery($team)->exists();

        return ['isSetup' => ! $productionExists, 'productionStarted' => $productionExists, 'steps' => $steps, 'url' => route('onboarding.index', $team->slug)];
    }

    /** @return array{total: int, ready: int, draft: int, url: string} */
    private function botSummary(Team $team): array
    {
        return ['total' => $team->bots()->count(), 'ready' => $team->bots()->whereIn('status', [BotStatus::Ready->value, BotStatus::Published->value])->count(), 'draft' => $team->bots()->where('status', BotStatus::Draft->value)->count(), 'url' => route('bots.index', $team->slug)];
    }

    /** @return array{active: int, total: int, url: string} */
    private function channelSummary(Team $team): array
    {
        $bot = $team->bots()->first();

        return ['active' => $team->channelConnections()->where('status', 'active')->count(), 'total' => $team->channelConnections()->count(), 'url' => $bot instanceof Bot ? route('bots.channels.index', [$team->slug, $bot]) : route('bots.index', $team->slug)];
    }

    /** @return list<array{key: string, url: string}> */
    private function quickActions(Team $team, User $user): array
    {
        $actions = [];
        $add = function (TeamPermission $permission, string $key, string $route, array|string $parameters = []) use (&$actions, $team, $user): void {
            if ($user->hasTeamPermission($team, $permission)) {
                $actions[] = ['key' => $key, 'url' => route($route, $parameters === [] ? $team->slug : $parameters)];
            }
        };
        $add(TeamPermission::BotsUpdate, 'create_bot', 'bots.create');
        $add(TeamPermission::DataSourcesManage, 'add_data_source', 'data-sources.create');
        if ($team->bots()->exists()) {
            $bot = $team->bots()->firstOrFail();
            if ($user->hasTeamPermission($team, TeamPermission::BotTestsManage)) {
                $actions[] = ['key' => 'run_bot_test', 'url' => route('bots.tests.index', [$team->slug, $bot])];
            }
            if ($user->hasTeamPermission($team, TeamPermission::ChannelsManage)) {
                $actions[] = ['key' => 'configure_channel', 'url' => route('bots.channels.index', [$team->slug, $bot])];
            }
        }
        if ($user->hasTeamPermission($team, TeamPermission::ConversationsView)) {
            $actions[] = ['key' => 'open_inbox', 'url' => route('conversations.index', $team->slug)];
        }

        return $actions;
    }
}
