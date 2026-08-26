import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChartNoAxesCombined, Search } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import Heading from '@/components/heading';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { index as analyticsIndex } from '@/routes/analytics';
import type {
    AnalyticsActionMetric,
    AnalyticsPageProps,
    AnalyticsRange,
} from '@/types';

const ranges: Array<{ value: AnalyticsRange; label: string }> = [
    { value: 'today', label: 'Today' },
    { value: '7d', label: 'Last 7 days' },
    { value: '30d', label: 'Last 30 days' },
    { value: '90d', label: 'Last 90 days' },
];

function number(value: number): string {
    return new Intl.NumberFormat().format(value);
}

function percentage(value: number | null): string {
    return value === null ? '—' : `${value.toFixed(1)}%`;
}

function AnalyticsChart({
    points,
}: {
    points: AnalyticsPageProps['timeseries']['conversations'];
}) {
    if (points.length === 0 || points.every((point) => point.value === 0)) {
        return (
            <div className="flex h-56 items-center justify-center rounded-lg border border-dashed text-sm text-muted-foreground">
                No conversation activity in this period.
            </div>
        );
    }

    const width = 720;
    const height = 220;
    const padding = 18;
    const max = Math.max(...points.map((point) => point.value), 1);
    const step =
        points.length === 1 ? 0 : (width - padding * 2) / (points.length - 1);
    const coordinates = points.map((point, index) => {
        const x = padding + step * index;
        const y =
            height - padding - (point.value / max) * (height - padding * 2);

        return `${x},${y}`;
    });

    return (
        <div className="overflow-x-auto">
            <svg
                viewBox={`0 0 ${width} ${height}`}
                className="h-56 w-full min-w-[36rem]"
                role="img"
                aria-label="Conversations over time"
            >
                {[0, 1, 2, 3].map((line) => {
                    const y = padding + (line * (height - padding * 2)) / 3;

                    return (
                        <line
                            key={line}
                            x1={padding}
                            x2={width - padding}
                            y1={y}
                            y2={y}
                            className="stroke-border"
                        />
                    );
                })}
                <polyline
                    points={coordinates.join(' ')}
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="3"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    className="text-primary"
                />
                {points.map((point, index) => {
                    const [x, y] = coordinates[index].split(',');

                    return (
                        <circle
                            key={point.date}
                            cx={x}
                            cy={y}
                            r="4"
                            className="fill-background stroke-primary"
                            strokeWidth="2"
                        />
                    );
                })}
            </svg>
            <div className="flex justify-between gap-2 px-1 text-xs text-muted-foreground">
                <span>{points[0].date}</span>
                <span>{points[points.length - 1].date}</span>
            </div>
        </div>
    );
}

function ActionRows({ actions }: { actions: AnalyticsActionMetric[] }) {
    return (
        <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
                <thead className="border-b text-xs text-muted-foreground">
                    <tr>
                        <th className="px-2 py-3 font-medium">Action</th>
                        <th className="px-2 py-3 text-right font-medium">
                            Completed
                        </th>
                        <th className="px-2 py-3 text-right font-medium">
                            Failed
                        </th>
                        <th className="px-2 py-3 text-right font-medium">
                            Cancelled
                        </th>
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {actions.map((action) => (
                        <tr key={action.key}>
                            <td className="px-2 py-3 font-medium">
                                {action.label}
                            </td>
                            <td className="px-2 py-3 text-right">
                                {number(action.completed)}
                            </td>
                            <td className="px-2 py-3 text-right">
                                {number(action.failed)}
                            </td>
                            <td className="px-2 py-3 text-right">
                                {number(action.cancelled)}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

export default function Analytics({
    filters,
    botOptions,
    summary,
    timeseries,
    capabilities,
    actions,
    bots,
}: AnalyticsPageProps) {
    const { t } = useTranslation();
    const { currentTeam } = usePage().props;

    if (!currentTeam) {
        return null;
    }

    const analyticsUrl = (
        range: AnalyticsRange,
        bot: string | null = filters.bot,
    ) =>
        analyticsIndex(currentTeam.slug, {
            query: { range, bot },
        }).url;

    const cards = [
        ['Conversations', summary.conversations],
        ['Unique visitors', summary.visitors],
        ['Messages', summary.messages],
        ['Searches', summary.searches],
        ['Completed actions', summary.completedActions],
        ['Failed actions', summary.failedActions],
    ] as const;

    return (
        <>
            <Head title={t('navigation.analytics')} />

            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
                    <Heading
                        variant="small"
                        title={t('navigation.analytics')}
                        description="Understand how your Bots are performing for this team."
                    />

                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                        <div className="flex flex-wrap gap-1 rounded-lg border p-1">
                            {ranges.map((range) => (
                                <Link
                                    key={range.value}
                                    href={analyticsUrl(range.value)}
                                    className={`rounded-md px-3 py-1.5 text-sm transition-colors ${
                                        filters.range === range.value
                                            ? 'bg-primary text-primary-foreground'
                                            : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                                    }`}
                                >
                                    {range.label}
                                </Link>
                            ))}
                        </div>

                        <div className="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm">
                            <Search className="size-4 text-muted-foreground" />
                            <label htmlFor="analytics-bot" className="sr-only">
                                Filter by Bot
                            </label>
                            <select
                                id="analytics-bot"
                                value={filters.bot ?? ''}
                                onChange={(event) => {
                                    router.get(
                                        analyticsUrl(
                                            filters.range,
                                            event.target.value || null,
                                        ),
                                    );
                                }}
                                className="bg-transparent outline-none"
                            >
                                <option value="">All Bots</option>
                                {botOptions.map((bot) => (
                                    <option key={bot.slug} value={bot.slug}>
                                        {bot.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {cards.map(([label, value]) => (
                        <Card key={label}>
                            <CardHeader className="gap-2">
                                <CardTitle className="text-sm font-medium text-muted-foreground">
                                    {label}
                                </CardTitle>
                                <p className="text-3xl font-semibold tracking-tight">
                                    {number(value)}
                                </p>
                            </CardHeader>
                        </Card>
                    ))}
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <ChartNoAxesCombined className="size-5 text-primary" />
                            Conversation activity
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <AnalyticsChart points={timeseries.conversations} />
                    </CardContent>
                </Card>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Business outcomes</CardTitle>
                        </CardHeader>
                        <CardContent className="grid grid-cols-2 gap-4">
                            {(
                                [
                                    ['Leads captured', summary.leadsCaptured],
                                    ['Support tickets', summary.supportTickets],
                                    [
                                        'Appointments booked',
                                        summary.appointmentsBooked,
                                    ],
                                    ['Add to cart', summary.addToCart],
                                ] as Array<[string, number]>
                            ).map(([label, value]) => (
                                <div
                                    key={label}
                                    className="rounded-lg bg-muted/50 p-4"
                                >
                                    <p className="text-sm text-muted-foreground">
                                        {label}
                                    </p>
                                    <p className="mt-1 text-2xl font-semibold">
                                        {number(value)}
                                    </p>
                                </div>
                            ))}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Search performance</CardTitle>
                        </CardHeader>
                        <CardContent className="grid grid-cols-2 gap-4">
                            <div className="rounded-lg bg-muted/50 p-4">
                                <p className="text-sm text-muted-foreground">
                                    Zero-result searches
                                </p>
                                <p className="mt-1 text-2xl font-semibold">
                                    {number(summary.zeroResultSearches)}
                                </p>
                            </div>
                            <div className="rounded-lg bg-muted/50 p-4">
                                <p className="text-sm text-muted-foreground">
                                    Average results
                                </p>
                                <p className="mt-1 text-2xl font-semibold">
                                    {summary.averageResultCount === null
                                        ? '—'
                                        : summary.averageResultCount.toFixed(1)}
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Capability usage</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="divide-y">
                                {capabilities.map((capability) => (
                                    <div
                                        key={capability.key}
                                        className="flex items-center justify-between py-3"
                                    >
                                        <span className="text-sm font-medium">
                                            {capability.label}
                                        </span>
                                        <span className="text-sm text-muted-foreground">
                                            {number(capability.count)}
                                        </span>
                                    </div>
                                ))}
                            </div>
                            <p className="mt-4 text-xs text-muted-foreground">
                                Counts are shown only for capabilities
                                represented by persisted search or action
                                telemetry.
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Action performance</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ActionRows actions={actions} />
                            <div className="mt-4 grid grid-cols-2 gap-3 border-t pt-4 text-sm">
                                <span className="text-muted-foreground">
                                    Actions proposed
                                </span>
                                <span className="text-right font-medium">
                                    {number(summary.actionsProposed)}
                                </span>
                                <span className="text-muted-foreground">
                                    Success rate
                                </span>
                                <span className="text-right font-medium">
                                    {percentage(summary.actionSuccessRate)}
                                </span>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Bot performance</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {bots.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No Bots are available for this team yet.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm">
                                    <thead className="border-b text-xs text-muted-foreground">
                                        <tr>
                                            <th className="px-2 py-3 font-medium">
                                                Bot
                                            </th>
                                            <th className="px-2 py-3 text-right font-medium">
                                                Conversations
                                            </th>
                                            <th className="px-2 py-3 text-right font-medium">
                                                Messages
                                            </th>
                                            <th className="px-2 py-3 text-right font-medium">
                                                Searches
                                            </th>
                                            <th className="px-2 py-3 text-right font-medium">
                                                Completed actions
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {bots.map((bot) => (
                                            <tr key={bot.slug}>
                                                <td className="px-2 py-3 font-medium">
                                                    {bot.name}
                                                </td>
                                                <td className="px-2 py-3 text-right">
                                                    {number(bot.conversations)}
                                                </td>
                                                <td className="px-2 py-3 text-right">
                                                    {number(bot.messages)}
                                                </td>
                                                <td className="px-2 py-3 text-right">
                                                    {number(bot.searches)}
                                                </td>
                                                <td className="px-2 py-3 text-right">
                                                    {number(
                                                        bot.completedActions,
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

Analytics.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Analytics',
            href: props.currentTeam
                ? analyticsIndex(props.currentTeam.slug)
                : '/',
        },
    ],
});
