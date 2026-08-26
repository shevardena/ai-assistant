import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    CheckCircle2,
    CircleX,
    Eye,
    MinusCircle,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { show as actionShow } from '@/routes/actions';
import { show as dataSourceShow } from '@/routes/data-sources';
import {
    index as integrationHealthIndex,
    show as integrationHealthShow,
} from '@/routes/integration-health';
import type {
    IntegrationFailureItem,
    IntegrationHealthItem,
    IntegrationHealthPageProps,
    IntegrationHealthRange,
    IntegrationHealthState,
} from '@/types';

const ranges: Array<{ value: IntegrationHealthRange; label: string }> = [
    { value: 'today', label: 'Today' },
    { value: '7d', label: 'Last 7 days' },
    { value: '30d', label: 'Last 30 days' },
    { value: '90d', label: 'Last 90 days' },
];

function date(value: string | null): string {
    return value
        ? new Intl.DateTimeFormat(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(new Date(value))
        : '—';
}

function typeLabel(type: IntegrationHealthItem['type']): string {
    return type === 'rest_api' ? 'REST API' : type === 'graphql_api' ? 'GraphQL API' : 'Uploaded file';
}

function healthVariant(
    health: IntegrationHealthState,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    return health === 'error'
        ? 'destructive'
        : health === 'healthy'
          ? 'default'
          : health === 'inactive'
            ? 'outline'
            : 'secondary';
}

function HealthIcon({ health }: { health: IntegrationHealthState }) {
    const Icon =
        health === 'healthy'
            ? CheckCircle2
            : health === 'error'
              ? CircleX
              : health === 'inactive'
                ? MinusCircle
                : AlertTriangle;

    return <Icon className="size-4" />;
}

function FailureRow({
    failure,
    teamSlug,
}: {
    failure: IntegrationFailureItem;
    teamSlug: string;
}) {
    const content = (
        <div className="flex items-start justify-between gap-4 py-3">
            <div className="min-w-0">
                <p className="font-medium">{failure.errorLabel}</p>
                <p className="text-sm text-muted-foreground">
                    {failure.source.name}
                    {failure.operation ? ` · ${failure.operation.name}` : ''}
                    {failure.bot ? ` · ${failure.bot.name}` : ''}
                </p>
            </div>
            <time
                className="shrink-0 text-xs text-muted-foreground"
                dateTime={failure.at ?? undefined}
            >
                {date(failure.at)}
            </time>
        </div>
    );

    return failure.actionReference ? (
        <Link
            href={actionShow([teamSlug, failure.actionReference]).url}
            className="block hover:bg-muted/40"
        >
            {content}
        </Link>
    ) : (
        <div>{content}</div>
    );
}

export default function IntegrationHealthIndex({
    filters,
    dataSourceOptions,
    healthOptions,
    summary,
    items,
    recentFailures,
}: IntegrationHealthPageProps) {
    const { t } = useTranslation();
    const { currentTeam } = usePage().props;

    if (!currentTeam) {
        return null;
    }

    const filterUrl = (
        changes: Partial<IntegrationHealthPageProps['filters']>,
    ) =>
        integrationHealthIndex(currentTeam.slug, {
            query: {
                range: changes.range ?? filters.range,
                data_source: changes.dataSource ?? filters.dataSource,
                health: changes.health ?? filters.health,
            },
        }).url;

    return (
        <>
            <Head title={t('navigation.integration_health')} />

            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
                    <Heading
                        variant="small"
                        title={t('navigation.integration_health')}
                        description="Review the operational state of this team's data sources and connected actions."
                    />

                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                        <div className="flex flex-wrap gap-1 rounded-lg border p-1">
                            {ranges.map((range) => (
                                <Link
                                    key={range.value}
                                    href={filterUrl({ range: range.value })}
                                    className={`rounded-md px-3 py-1.5 text-sm transition-colors ${filters.range === range.value ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted hover:text-foreground'}`}
                                >
                                    {range.label}
                                </Link>
                            ))}
                        </div>
                        <select
                            aria-label="Filter by data source"
                            value={filters.dataSource ?? ''}
                            onChange={(event) =>
                                router.get(
                                    filterUrl({
                                        dataSource: event.target.value
                                            ? Number(event.target.value)
                                            : null,
                                    }),
                                )
                            }
                            className="rounded-lg border bg-transparent px-3 py-2 text-sm"
                        >
                            <option value="">All data sources</option>
                            {dataSourceOptions.map((source) => (
                                <option key={source.id} value={source.id}>
                                    {source.name}
                                </option>
                            ))}
                        </select>
                        <select
                            aria-label="Filter by health"
                            value={filters.health}
                            onChange={(event) =>
                                router.get(
                                    filterUrl({
                                        health: event.target
                                            .value as IntegrationHealthPageProps['filters']['health'],
                                    }),
                                )
                            }
                            className="rounded-lg border bg-transparent px-3 py-2 text-sm"
                        >
                            <option value="all">All health states</option>
                            {healthOptions.map((option) => (
                                <option key={option.key} value={option.key}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    {[
                        {
                            label: 'Integrations',
                            value: summary.integrations,
                            Icon: Activity,
                        },
                        {
                            label: 'Healthy',
                            value: summary.healthy,
                            Icon: CheckCircle2,
                        },
                        {
                            label: 'Warnings',
                            value: summary.warnings,
                            Icon: AlertTriangle,
                        },
                        {
                            label: 'Errors',
                            value: summary.errors,
                            Icon: CircleX,
                        },
                        {
                            label: 'Recent failures',
                            value: summary.recentFailures,
                            Icon: AlertTriangle,
                        },
                    ].map(
                        ({
                            label,
                            value,
                            Icon,
                        }: {
                            label: string;
                            value: number;
                            Icon: LucideIcon;
                        }) => (
                            <Card key={label}>
                                <CardHeader className="gap-2">
                                    <CardTitle className="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                                        <Icon className="size-4" />
                                        {label}
                                    </CardTitle>
                                    <p className="text-3xl font-semibold tracking-tight">
                                        {value}
                                    </p>
                                </CardHeader>
                            </Card>
                        ),
                    )}
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Data source health</CardTitle>
                    </CardHeader>
                    <CardContent className="px-0">
                        {items.length > 0 ? (
                            <div className="divide-y">
                                {items.map((item) => (
                                    <div
                                        key={item.id}
                                        className="grid gap-4 px-6 py-4 lg:grid-cols-[minmax(0,1fr)_10rem_12rem_12rem_auto] lg:items-center"
                                    >
                                        <div className="min-w-0">
                                            <Link
                                                href={
                                                    integrationHealthShow([
                                                        currentTeam.slug,
                                                        item.id,
                                                    ]).url
                                                }
                                                className="font-medium hover:underline"
                                            >
                                                {item.name}
                                            </Link>
                                            <p className="text-sm text-muted-foreground">
                                                {typeLabel(item.type)} ·{' '}
                                                {item.datasets.length} dataset
                                                {item.datasets.length === 1
                                                    ? ''
                                                    : 's'}{' '}
                                                · {item.bots.length} Bot
                                                {item.bots.length === 1
                                                    ? ''
                                                    : 's'}
                                            </p>
                                        </div>
                                        <Badge
                                            variant={healthVariant(item.health)}
                                        >
                                            <HealthIcon health={item.health} />
                                            {item.healthLabel}
                                        </Badge>
                                        <div className="text-sm">
                                            <p className="text-muted-foreground">
                                                Last successful sync
                                            </p>
                                            <p>
                                                {date(
                                                    item.lastSuccessfulRunAt ??
                                                        item.lastSyncedAt,
                                                )}
                                            </p>
                                        </div>
                                        <div className="text-sm">
                                            <p className="text-muted-foreground">
                                                Recent failures
                                            </p>
                                            <p>
                                                {item.recentFailureCount}
                                                {item.lastFailureLabel
                                                    ? ` · ${item.lastFailureLabel}`
                                                    : ''}
                                            </p>
                                        </div>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            asChild
                                        >
                                            <Link
                                                href={
                                                    dataSourceShow([
                                                        currentTeam.slug,
                                                        item.id,
                                                    ]).url
                                                }
                                                aria-label={`View ${item.name}`}
                                            >
                                                <Eye />
                                            </Link>
                                        </Button>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <div className="px-6 py-12 text-center text-sm text-muted-foreground">
                                No integrations match these filters.
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Recent failures</CardTitle>
                    </CardHeader>
                    <CardContent className="divide-y">
                        {recentFailures.length > 0 ? (
                            recentFailures.map((failure) => (
                                <FailureRow
                                    key={`${failure.kind}-${failure.id}`}
                                    failure={failure}
                                    teamSlug={currentTeam.slug}
                                />
                            ))
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                No recent failures in this period.
                            </p>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}
