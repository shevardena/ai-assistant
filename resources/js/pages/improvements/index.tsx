import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowUpRight, Bot, CircleAlert, Sparkles } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { index as improvementsIndex } from '@/routes/improvements';
import type {
    ImprovementCategory,
    ImprovementCenterPageProps,
    ImprovementFilters,
    ImprovementPriority,
} from '@/types';

const ranges: Array<{ value: ImprovementFilters['range']; label: string }> = [
    { value: 'today', label: 'Today' },
    { value: '7d', label: '7 days' },
    { value: '30d', label: '30 days' },
    { value: '90d', label: '90 days' },
];

const typeLabels: Record<ImprovementCategory | 'all', string> = {
    all: 'All types',
    customer_questions: 'Customer questions',
    search: 'Search',
    data: 'Data',
    integrations: 'Integrations',
    actions: 'Actions',
    configuration: 'Configuration',
};

const priorityLabels: Record<ImprovementPriority | 'all', string> = {
    all: 'All priorities',
    high: 'High priority',
    medium: 'Medium priority',
    low: 'Recommendations',
};

function priorityVariant(
    priority: ImprovementPriority,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    return priority === 'high'
        ? 'destructive'
        : priority === 'medium'
          ? 'secondary'
          : 'outline';
}

function formatDate(value: string | null): string {
    return value
        ? new Intl.DateTimeFormat(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(new Date(value))
        : '—';
}

function number(value: number): string {
    return new Intl.NumberFormat().format(value);
}

export default function ImprovementIndex({
    filters,
    botOptions,
    summary,
    opportunities,
    total,
}: ImprovementCenterPageProps) {
    const { t } = useTranslation();
    const { currentTeam } = usePage().props;

    if (!currentTeam) {
        return null;
    }

    const visit = (changes: Partial<ImprovementFilters>) => {
        const next = { ...filters, ...changes };

        router.get(
            improvementsIndex(currentTeam.slug, {
                query: {
                    bot: next.bot,
                    range: next.range,
                    type: next.type,
                    priority: next.priority,
                },
            }).url,
        );
    };

    const hasFilters =
        filters.bot !== null ||
        filters.range !== '30d' ||
        filters.type !== 'all' ||
        filters.priority !== 'all';

    return (
        <>
            <Head title={t('navigation.ai_improvements')} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
                    <Heading
                        variant="small"
                        title={t('navigation.ai_improvements')}
                        description="Prioritized, evidence-based opportunities from your customer and operational signals."
                    />
                    <div className="flex flex-wrap gap-1 rounded-lg border p-1">
                        {ranges.map((range) => (
                            <Link
                                key={range.value}
                                href={
                                    improvementsIndex(currentTeam.slug, {
                                        query: {
                                            ...filters,
                                            range: range.value,
                                        },
                                    }).url
                                }
                                className={`rounded-md px-3 py-1.5 text-sm ${filters.range === range.value ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted hover:text-foreground'}`}
                            >
                                {range.label}
                            </Link>
                        ))}
                    </div>
                </div>

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    {[
                        ['Open improvements', summary.open],
                        ['High priority', summary.highPriority],
                        ['Customer questions', summary.customerQuestions],
                        [
                            'Data/integration issues',
                            summary.dataIntegrationIssues,
                        ],
                    ].map(([label, value]) => (
                        <Card key={label}>
                            <CardContent className="p-4">
                                <p className="text-sm text-muted-foreground">
                                    {label}
                                </p>
                                <p className="mt-2 text-3xl font-semibold tracking-tight">
                                    {number(value as number)}
                                </p>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                <div className="flex flex-col gap-3 rounded-xl border p-3 md:flex-row">
                    <select
                        aria-label="Filter by Bot"
                        value={filters.bot ?? ''}
                        onChange={(event) =>
                            visit({ bot: event.target.value || null })
                        }
                        className="rounded-lg border bg-transparent px-3 py-2 text-sm"
                    >
                        <option value="">All Bots</option>
                        {botOptions.map((bot) => (
                            <option key={bot.slug} value={bot.slug}>
                                {bot.name}
                            </option>
                        ))}
                    </select>
                    <select
                        aria-label="Filter by improvement type"
                        value={filters.type}
                        onChange={(event) =>
                            visit({
                                type: event.target.value as
                                    ImprovementCategory | 'all',
                            })
                        }
                        className="rounded-lg border bg-transparent px-3 py-2 text-sm"
                    >
                        {Object.entries(typeLabels).map(([key, label]) => (
                            <option key={key} value={key}>
                                {label}
                            </option>
                        ))}
                    </select>
                    <select
                        aria-label="Filter by priority"
                        value={filters.priority}
                        onChange={(event) =>
                            visit({
                                priority: event.target.value as
                                    ImprovementPriority | 'all',
                            })
                        }
                        className="rounded-lg border bg-transparent px-3 py-2 text-sm"
                    >
                        {Object.entries(priorityLabels).map(([key, label]) => (
                            <option key={key} value={key}>
                                {label}
                            </option>
                        ))}
                    </select>
                </div>

                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                    <Sparkles className="size-4" />
                    {number(total)}{' '}
                    {total === 1 ? 'opportunity' : 'opportunities'}
                </div>

                {opportunities.length === 0 ? (
                    <div className="rounded-xl border border-dashed p-10 text-center">
                        <CircleAlert className="mx-auto size-8 text-muted-foreground" />
                        <p className="mt-3 font-medium">
                            {hasFilters
                                ? 'No improvements match these filters.'
                                : 'No improvement opportunities found.'}
                        </p>
                        <p className="mx-auto mt-1 max-w-xl text-sm text-muted-foreground">
                            Your current data, integrations, and customer
                            interactions do not show any actionable issues for
                            this period.
                        </p>
                    </div>
                ) : (
                    <div className="grid gap-4 xl:grid-cols-2">
                        {opportunities.map((opportunity) => (
                            <Card
                                key={`${opportunity.type}-${opportunity.title}-${opportunity.bot?.id ?? 'team'}`}
                            >
                                <CardContent className="flex h-full flex-col gap-4 p-5">
                                    <div className="flex items-start justify-between gap-4">
                                        <Badge
                                            variant={priorityVariant(
                                                opportunity.priority,
                                            )}
                                        >
                                            {opportunity.priority.toUpperCase()}
                                        </Badge>
                                        <span className="text-xs text-muted-foreground">
                                            {formatDate(opportunity.lastSeenAt)}
                                        </span>
                                    </div>
                                    <div>
                                        <h2 className="text-base font-semibold tracking-tight">
                                            {opportunity.title}
                                        </h2>
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            {opportunity.description}
                                        </p>
                                    </div>
                                    <div className="flex flex-wrap gap-2 text-sm">
                                        {opportunity.bot ? (
                                            <span className="inline-flex items-center gap-1 rounded-md bg-muted px-2 py-1">
                                                <Bot className="size-3.5" />
                                                {opportunity.bot.name}
                                            </span>
                                        ) : null}
                                        {opportunity.evidence.map((item) => (
                                            <span
                                                key={`${item.label}-${item.value}`}
                                                className="rounded-md border px-2 py-1 text-muted-foreground"
                                            >
                                                {item.label}: {item.value}
                                            </span>
                                        ))}
                                    </div>
                                    <div className="mt-auto flex items-center justify-between gap-4 border-t pt-4">
                                        <p className="text-sm text-muted-foreground">
                                            {opportunity.recommendation}
                                        </p>
                                        <Link
                                            href={opportunity.destination.url}
                                            className="inline-flex shrink-0 items-center gap-1 text-sm font-medium hover:underline"
                                        >
                                            {opportunity.destination.label}
                                            <ArrowUpRight className="size-4" />
                                        </Link>
                                    </div>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}
