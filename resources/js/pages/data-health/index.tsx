import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    CircleX,
    Database,
    Eye,
    MinusCircle,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    show as dataHealthShow,
    index as dataHealthIndex,
} from '@/routes/data-health';
import { show as dataSourceShow } from '@/routes/data-sources';
import type {
    DataHealthDataset,
    DataHealthFilters,
    DataHealthPageProps,
    DataHealthRange,
    DataHealthState,
} from '@/types';

const ranges: Array<{ value: DataHealthRange; label: string }> = [
    { value: 'today', label: 'Today' },
    { value: '7d', label: 'Last 7 days' },
    { value: '30d', label: 'Last 30 days' },
    { value: '90d', label: 'Last 90 days' },
];

const healthOptions: Array<{ value: 'all' | DataHealthState; label: string }> =
    [
        { value: 'all', label: 'All health states' },
        { value: 'healthy', label: 'Healthy' },
        { value: 'warning', label: 'Warnings' },
        { value: 'error', label: 'Errors' },
        { value: 'inactive', label: 'Inactive' },
    ];

function healthVariant(
    health: DataHealthState,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    return health === 'error'
        ? 'destructive'
        : health === 'healthy'
          ? 'default'
          : health === 'inactive'
            ? 'outline'
            : 'secondary';
}

function HealthIcon({ health }: { health: DataHealthState }) {
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

function paginationLabel(label: string): string {
    return label.replace('&laquo;', 'Previous').replace('&raquo;', 'Next');
}

export default function DataHealthIndex({
    filters,
    dataSourceOptions,
    summary,
    datasets,
}: DataHealthPageProps) {
    const { t } = useTranslation();
    const { currentTeam } = usePage().props;
    const [search, setSearch] = useState(filters.search ?? '');

    if (!currentTeam) {
        return null;
    }

    const filterUrl = (changes: Partial<DataHealthFilters>) =>
        dataHealthIndex(currentTeam.slug, {
            query: {
                range: changes.range ?? filters.range,
                data_source:
                    changes.dataSource !== undefined
                        ? changes.dataSource
                        : filters.dataSource,
                health: changes.health ?? filters.health,
                search:
                    changes.search !== undefined
                        ? changes.search
                        : filters.search,
            },
        }).url;

    const applySearch = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        router.get(filterUrl({ search: search.trim() || null }));
    };

    return (
        <>
            <Head title={t('navigation.data_health')} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
                    <Heading
                        variant="small"
                        title={t('navigation.data_health')}
                        description="Monitor dataset readiness, record availability, and field coverage for this team."
                    />
                    <div className="flex flex-wrap gap-1 rounded-lg border p-1">
                        {ranges.map((range) => (
                            <Link
                                key={range.value}
                                href={filterUrl({ range: range.value })}
                                className={`rounded-md px-3 py-1.5 text-sm ${filters.range === range.value ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted hover:text-foreground'}`}
                            >
                                {range.label}
                            </Link>
                        ))}
                    </div>
                </div>

                <div className="flex flex-col gap-3 sm:flex-row">
                    <form onSubmit={applySearch} className="flex flex-1 gap-2">
                        <input
                            aria-label="Search datasets"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Search datasets"
                            className="min-w-0 flex-1 rounded-lg border bg-transparent px-3 py-2 text-sm"
                        />
                        <Button type="submit" variant="outline">
                            Search
                        </Button>
                    </form>
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
                                        .value as DataHealthFilters['health'],
                                }),
                            )
                        }
                        className="rounded-lg border bg-transparent px-3 py-2 text-sm"
                    >
                        {healthOptions.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    {[
                        {
                            label: 'Datasets',
                            value: summary.datasets,
                            Icon: Database,
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
                            label: 'Active records',
                            value: summary.records,
                            Icon: Database,
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
                            <Card key={String(label)}>
                                <CardHeader className="gap-2">
                                    <CardTitle className="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                                        <Icon className="size-4" />
                                        {label}
                                    </CardTitle>
                                    <p className="text-3xl font-semibold tracking-tight">
                                        {Number(value).toLocaleString()}
                                    </p>
                                </CardHeader>
                            </Card>
                        ),
                    )}
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Dataset health</CardTitle>
                    </CardHeader>
                    <CardContent className="px-0">
                        {datasets.data.length > 0 ? (
                            <div className="divide-y">
                                {datasets.data.map(
                                    (dataset: DataHealthDataset) => (
                                        <div
                                            key={dataset.id}
                                            className="grid gap-4 px-6 py-4 lg:grid-cols-[minmax(0,1fr)_9rem_12rem_9rem_8rem_auto] lg:items-center"
                                        >
                                            <div className="min-w-0">
                                                <Link
                                                    href={
                                                        dataHealthShow([
                                                            currentTeam.slug,
                                                            dataset.id,
                                                        ]).url
                                                    }
                                                    className="font-medium hover:underline"
                                                >
                                                    {dataset.name}
                                                </Link>
                                                <p className="truncate text-sm text-muted-foreground">
                                                    {dataset.slug}
                                                </p>
                                            </div>
                                            <Badge
                                                variant={healthVariant(
                                                    dataset.health,
                                                )}
                                            >
                                                <HealthIcon
                                                    health={dataset.health}
                                                />
                                                {dataset.healthLabel}
                                            </Badge>
                                            <div className="text-sm">
                                                <p className="text-muted-foreground">
                                                    Source
                                                </p>
                                                {dataset.dataSource ? (
                                                    <Link
                                                        href={
                                                            dataSourceShow([
                                                                currentTeam.slug,
                                                                dataset
                                                                    .dataSource
                                                                    .id,
                                                            ]).url
                                                        }
                                                        className="hover:underline"
                                                    >
                                                        {
                                                            dataset.dataSource
                                                                .name
                                                        }
                                                    </Link>
                                                ) : (
                                                    <p>Detached</p>
                                                )}
                                            </div>
                                            <div className="text-sm">
                                                <p className="text-muted-foreground">
                                                    Active records
                                                </p>
                                                <p>
                                                    {dataset.activeRecords.toLocaleString()}
                                                </p>
                                            </div>
                                            <div className="text-sm">
                                                <p className="text-muted-foreground">
                                                    Issues
                                                </p>
                                                <p>{dataset.issueCount}</p>
                                            </div>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                asChild
                                            >
                                                <Link
                                                    href={
                                                        dataHealthShow([
                                                            currentTeam.slug,
                                                            dataset.id,
                                                        ]).url
                                                    }
                                                    aria-label={`View ${dataset.name} data health`}
                                                >
                                                    <Eye />
                                                </Link>
                                            </Button>
                                        </div>
                                    ),
                                )}
                            </div>
                        ) : (
                            <div className="px-6 py-12 text-center text-sm text-muted-foreground">
                                No datasets match these filters.
                            </div>
                        )}
                    </CardContent>
                </Card>

                {datasets.last_page > 1 ? (
                    <nav
                        className="flex flex-wrap items-center justify-center gap-2"
                        aria-label="Data health pagination"
                    >
                        {datasets.links.map((link, index) =>
                            link.url ? (
                                <Button
                                    key={`${link.label}-${index}`}
                                    variant={
                                        link.active ? 'default' : 'outline'
                                    }
                                    size="sm"
                                    asChild
                                >
                                    <Link href={link.url}>
                                        {paginationLabel(link.label)}
                                    </Link>
                                </Button>
                            ) : (
                                <Button
                                    key={`${link.label}-${index}`}
                                    variant="outline"
                                    size="sm"
                                    disabled
                                >
                                    {paginationLabel(link.label)}
                                </Button>
                            ),
                        )}
                    </nav>
                ) : null}
            </div>
        </>
    );
}
