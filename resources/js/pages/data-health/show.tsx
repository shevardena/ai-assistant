import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    AlertTriangle,
    CheckCircle2,
    CircleX,
    ExternalLink,
} from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { show as botShow } from '@/routes/bots';
import { index as dataHealthIndex } from '@/routes/data-health';
import { show as dataSourceShow } from '@/routes/data-sources';
import { index as integrationHealthIndex } from '@/routes/integration-health';
import type { DataHealthDetailPageProps, DataHealthState } from '@/types';

function date(value: string | null): string {
    return value
        ? new Intl.DateTimeFormat(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(new Date(value))
        : '—';
}

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

export default function DataHealthShow({
    dataset,
    fieldCoverage,
    importHistory,
}: DataHealthDetailPageProps) {
    const { currentTeam } = usePage().props;

    if (!currentTeam) {
        return null;
    }

    return (
        <>
            <Head title={`${dataset.name} · Data Health`} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex items-center gap-3">
                    <Button variant="ghost" size="icon" asChild>
                        <Link
                            href={dataHealthIndex(currentTeam.slug).url}
                            aria-label="Back to Data Health"
                        >
                            <ArrowLeft />
                        </Link>
                    </Button>
                    <Heading
                        variant="small"
                        title={dataset.name}
                        description="Dataset quality and import detail"
                    />
                </div>

                <div className="flex flex-wrap items-center gap-3">
                    <Badge variant={healthVariant(dataset.health)}>
                        {dataset.healthLabel}
                    </Badge>
                    <span className="text-sm text-muted-foreground">
                        Dataset status: {dataset.statusLabel}
                    </span>
                    {dataset.dataSource ? (
                        <>
                            <Button variant="outline" size="sm" asChild>
                                <Link
                                    href={
                                        dataSourceShow([
                                            currentTeam.slug,
                                            dataset.dataSource.id,
                                        ]).url
                                    }
                                >
                                    Open data source <ExternalLink />
                                </Link>
                            </Button>
                            <Button variant="ghost" size="sm" asChild>
                                <Link
                                    href={
                                        integrationHealthIndex(currentTeam.slug)
                                            .url
                                    }
                                >
                                    Integration Health
                                </Link>
                            </Button>
                        </>
                    ) : null}
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    {[
                        [
                            'Active records',
                            dataset.activeRecords.toLocaleString(),
                        ],
                        [
                            'Inactive records',
                            dataset.inactiveRecords.toLocaleString(),
                        ],
                        ['Fields', dataset.totalFields.toLocaleString()],
                        ['Issues', dataset.issueCount.toLocaleString()],
                        [
                            'Last successful import',
                            date(dataset.lastSuccessfulImportAt),
                        ],
                    ].map(([label, value]) => (
                        <Card key={label}>
                            <CardHeader>
                                <CardTitle className="text-sm font-medium text-muted-foreground">
                                    {label}
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-lg font-semibold">{value}</p>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                {dataset.issues.length > 0 ? (
                    <Card>
                        <CardHeader>
                            <CardTitle>Issues</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {dataset.issues.map((issue, index) => (
                                <div
                                    key={`${issue.type}-${issue.field ?? index}`}
                                    className="flex items-start gap-3 rounded-lg border p-3 text-sm"
                                >
                                    {issue.severity === 'error' ? (
                                        <CircleX className="mt-0.5 size-4 text-destructive" />
                                    ) : (
                                        <AlertTriangle className="mt-0.5 size-4 text-amber-500" />
                                    )}
                                    <div>
                                        <p className="font-medium">
                                            {issue.message}
                                        </p>
                                        {issue.field ? (
                                            <p className="text-muted-foreground">
                                                Field: {issue.field}
                                            </p>
                                        ) : null}
                                    </div>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                ) : (
                    <div className="flex items-center gap-2 rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                        <CheckCircle2 className="size-4" />
                        No current data health issues.
                    </div>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>Field coverage</CardTitle>
                    </CardHeader>
                    <CardContent className="overflow-x-auto px-0">
                        {fieldCoverage.length > 0 ? (
                            <table className="w-full text-left text-sm">
                                <thead className="border-b text-xs text-muted-foreground">
                                    <tr>
                                        <th className="px-6 py-3 font-medium">
                                            Field
                                        </th>
                                        <th className="px-3 py-3 font-medium">
                                            Type
                                        </th>
                                        <th className="px-3 py-3 font-medium">
                                            Present
                                        </th>
                                        <th className="px-3 py-3 font-medium">
                                            Coverage
                                        </th>
                                        <th className="px-6 py-3 font-medium">
                                            Flags
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {fieldCoverage.map((field) => (
                                        <tr key={field.id}>
                                            <td className="px-6 py-3">
                                                <p className="font-medium">
                                                    {field.label}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {field.key}
                                                </p>
                                            </td>
                                            <td className="px-3 py-3">
                                                {field.dataType}
                                            </td>
                                            <td className="px-3 py-3">
                                                {field.presentCount.toLocaleString()}{' '}
                                                /{' '}
                                                {field.activeRecords.toLocaleString()}
                                            </td>
                                            <td className="px-3 py-3">
                                                {field.coverage === null
                                                    ? '—'
                                                    : `${field.coverage}%`}
                                                {field.coverage === 0 ? (
                                                    <AlertTriangle className="ml-1 inline size-4 text-amber-500" />
                                                ) : null}
                                            </td>
                                            <td className="px-6 py-3 text-xs text-muted-foreground">
                                                {[
                                                    field.isDisplayable
                                                        ? 'display'
                                                        : null,
                                                    field.isSearchable
                                                        ? 'search'
                                                        : null,
                                                    field.isFilterable
                                                        ? 'filter'
                                                        : null,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ') || '—'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        ) : (
                            <p className="px-6 py-8 text-sm text-muted-foreground">
                                No fields are configured.
                            </p>
                        )}
                    </CardContent>
                </Card>

                <div className="grid gap-6 xl:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Bots using this dataset</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2 text-sm">
                            {dataset.bots.length > 0 ? (
                                dataset.bots.map((bot) => (
                                    <p key={bot.id}>
                                        <Link
                                            className="hover:underline"
                                            href={
                                                botShow([
                                                    currentTeam.slug,
                                                    bot.id,
                                                ]).url
                                            }
                                        >
                                            {bot.name}
                                        </Link>
                                    </p>
                                ))
                            ) : (
                                <p className="text-muted-foreground">
                                    No Bots use this dataset.
                                </p>
                            )}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Import history</CardTitle>
                        </CardHeader>
                        <CardContent className="overflow-x-auto px-0">
                            {importHistory.length > 0 ? (
                                <table className="w-full text-left text-sm">
                                    <thead className="border-b text-xs text-muted-foreground">
                                        <tr>
                                            <th className="px-6 py-3 font-medium">
                                                Run
                                            </th>
                                            <th className="px-3 py-3 font-medium">
                                                Rows
                                            </th>
                                            <th className="px-3 py-3 font-medium">
                                                Duration
                                            </th>
                                            <th className="px-6 py-3 font-medium">
                                                Finished
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {importHistory.map((run) => (
                                            <tr key={run.id}>
                                                <td className="px-6 py-3">
                                                    <p className="font-medium">
                                                        {run.statusLabel}
                                                    </p>
                                                    {run.errorLabel ? (
                                                        <p className="text-xs text-destructive">
                                                            {run.errorLabel}
                                                        </p>
                                                    ) : null}
                                                </td>
                                                <td className="px-3 py-3">
                                                    {run.rowsRead} /{' '}
                                                    {run.rowsWritten} /{' '}
                                                    {run.rowsFailed}
                                                </td>
                                                <td className="px-3 py-3">
                                                    {run.durationMs === null
                                                        ? '—'
                                                        : `${run.durationMs} ms`}
                                                </td>
                                                <td className="px-6 py-3">
                                                    {date(
                                                        run.finishedAt ??
                                                            run.startedAt,
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            ) : (
                                <p className="px-6 py-8 text-sm text-muted-foreground">
                                    No imports recorded.
                                </p>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}
