import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, CircleX } from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { show as dataSourceShow } from '@/routes/data-sources';
import { index as integrationHealthIndex } from '@/routes/integration-health';
import type {
    IntegrationHealthDetailProps,
    IntegrationHealthState,
} from '@/types';

function date(value: string | null): string {
    return value
        ? new Intl.DateTimeFormat(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(new Date(value))
        : '—';
}

function typeLabel(type: 'file' | 'rest_api' | 'graphql_api'): string {
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

export default function IntegrationHealthShow({
    dataSource,
    operations,
    recentFailures,
}: IntegrationHealthDetailProps) {
    const { currentTeam } = usePage().props;

    if (!currentTeam) {
        return null;
    }

    return (
        <>
            <Head title={`${dataSource.name} · Integration Health`} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex items-center gap-3">
                    <Button variant="ghost" size="icon" asChild>
                        <Link
                            href={integrationHealthIndex(currentTeam.slug).url}
                            aria-label="Back to Integration Health"
                        >
                            <ArrowLeft />
                        </Link>
                    </Button>
                    <Heading
                        variant="small"
                        title={dataSource.name}
                        description={`${typeLabel(dataSource.type)} operational detail`}
                    />
                </div>

                <div className="flex flex-wrap items-center gap-3">
                    <Badge variant={healthVariant(dataSource.health)}>
                        {dataSource.healthLabel}
                    </Badge>
                    <span className="text-sm text-muted-foreground">
                        Current status: {dataSource.statusLabel}
                    </span>
                    <Button variant="outline" size="sm" asChild>
                        <Link
                            href={
                                dataSourceShow([
                                    currentTeam.slug,
                                    dataSource.id,
                                ]).url
                            }
                        >
                            Open data source
                        </Link>
                    </Button>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {[
                        ['Last run', date(dataSource.lastRunAt)],
                        [
                            'Last successful sync',
                            date(
                                dataSource.lastSuccessfulRunAt ??
                                    dataSource.lastSyncedAt,
                            ),
                        ],
                        [
                            'Recent failures',
                            dataSource.recentFailureCount.toString(),
                        ],
                        [
                            'Rows written',
                            dataSource.rowsWritten === null
                                ? '—'
                                : dataSource.rowsWritten.toLocaleString(),
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

                <div className="grid gap-6 xl:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Datasets</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2 text-sm">
                            {dataSource.datasets.length > 0 ? (
                                dataSource.datasets.map((dataset) => (
                                    <p key={dataset.id}>{dataset.name}</p>
                                ))
                            ) : (
                                <p className="text-muted-foreground">
                                    No datasets use this source.
                                </p>
                            )}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Bots</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2 text-sm">
                            {dataSource.bots.length > 0 ? (
                                dataSource.bots.map((bot) => (
                                    <p key={bot.id}>{bot.name}</p>
                                ))
                            ) : (
                                <p className="text-muted-foreground">
                                    No Bots use this source.
                                </p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>API operation health</CardTitle>
                    </CardHeader>
                    <CardContent className="overflow-x-auto px-0">
                        {operations.length > 0 ? (
                            <table className="w-full text-left text-sm">
                                <thead className="border-b text-xs text-muted-foreground">
                                    <tr>
                                        <th className="px-6 py-3 font-medium">
                                            Operation
                                        </th>
                                        <th className="px-3 py-3 font-medium">
                                            Mode
                                        </th>
                                        <th className="px-3 py-3 font-medium">
                                            Bots
                                        </th>
                                        <th className="px-3 py-3 font-medium">
                                            Calls
                                        </th>
                                        <th className="px-6 py-3 font-medium">
                                            Health data
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {operations.map((operation) => (
                                        <tr key={operation.id}>
                                            <td className="px-6 py-3 font-medium">
                                                {operation.name}
                                            </td>
                                            <td className="px-3 py-3">
                                                {operation.mode}
                                            </td>
                                            <td className="px-3 py-3">
                                                {operation.bots.length}
                                            </td>
                                            <td className="px-3 py-3">
                                                {operation.calls ?? '—'}
                                            </td>
                                            <td className="px-6 py-3 text-muted-foreground">
                                                {operation.telemetryAvailable
                                                    ? `${operation.failures ?? 0} failures`
                                                    : operation.telemetryMessage}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        ) : (
                            <p className="px-6 text-sm text-muted-foreground">
                                No API operations are attached to Bots.
                            </p>
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
                                <div
                                    key={`${failure.kind}-${failure.id}`}
                                    className="flex items-center justify-between gap-4 py-3"
                                >
                                    <div className="flex items-center gap-2">
                                        <CircleX className="size-4 text-destructive" />
                                        <div>
                                            <p className="font-medium">
                                                {failure.errorLabel}
                                            </p>
                                            <p className="text-sm text-muted-foreground">
                                                {failure.operation?.name ??
                                                    failure.dataset?.name ??
                                                    'Import'}
                                                {failure.bot
                                                    ? ` · ${failure.bot.name}`
                                                    : ''}
                                            </p>
                                        </div>
                                    </div>
                                    <time className="text-xs text-muted-foreground">
                                        {date(failure.at)}
                                    </time>
                                </div>
                            ))
                        ) : (
                            <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                <CheckCircle2 className="size-4" />
                                No recent failures in this period.
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}
