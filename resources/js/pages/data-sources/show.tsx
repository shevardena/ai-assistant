import { Form, Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    Database,
    FileText,
    Pencil,
    Trash2,
    Upload,
} from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import DeleteDataSourceDialog from '@/components/delete-data-source-dialog';
import DeleteSourceFileDialog from '@/components/delete-source-file-dialog';
import FormErrorSummary from '@/components/form-error-summary';
import Heading from '@/components/heading';
import SourceFileUploadForm from '@/components/source-file-upload-form';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    dataSourceStatusVariant,
    statusDescription,
    statusLabel,
} from '@/lib/status';
import { edit, index } from '@/routes/data-sources';
import { edit as editApi } from '@/routes/data-sources/api';
import {
    create as createOperation,
    edit as editOperation,
    sync as runSync,
} from '@/routes/data-sources/api-operations';
import syncActions from '@/routes/data-sources/api-operations/sync';
import syncSchedule from '@/routes/data-sources/api-operations/sync-schedule';
import { edit as editGraphql } from '@/routes/data-sources/graphql';
import type { DataSource, SourceFile } from '@/types';

type Props = {
    dataSource: DataSource;
};

function typeLabel(type: DataSource['type']): string {
    return type === 'rest_api'
        ? 'REST API'
        : type === 'graphql_api'
          ? 'GraphQL API'
          : 'Uploaded file';
}

function formatDate(value: string | null): string {
    return value
        ? new Intl.DateTimeFormat(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(new Date(value))
        : '—';
}

function formatFileSize(sizeBytes: number | null): string {
    if (sizeBytes === null) {
        return '—';
    }

    if (sizeBytes < 1024) {
        return `${sizeBytes} B`;
    }

    if (sizeBytes < 1024 * 1024) {
        return `${(sizeBytes / 1024).toFixed(1)} KB`;
    }

    return `${(sizeBytes / (1024 * 1024)).toFixed(1)} MB`;
}

function SyncScheduleControls({
    currentTeamSlug,
    dataSource,
    operation,
}: {
    currentTeamSlug: string;
    dataSource: DataSource;
    operation: NonNullable<DataSource['apiOperations']>[number];
}) {
    const schedule = operation.syncSchedule;
    const datasets = dataSource.datasets ?? [];
    const synced =
        operation.executionMode === 'read' && operation.type === 'query';

    if (!synced) {
        return (
            <p className="text-sm text-muted-foreground">
                Live operations run on demand and cannot use recurring sync.
            </p>
        );
    }

    const configuration = schedule?.configuration ?? {};
    const updatedSince = (configuration.updated_since ?? {}) as Record<
        string,
        string
    >;
    const cursor = (configuration.cursor ?? {}) as Record<string, string>;
    const args: [string, number, number] = [
        currentTeamSlug,
        dataSource.id,
        operation.id,
    ];
    const datasetId = schedule?.datasetId ?? datasets[0]?.id ?? '';

    return (
        <div className="space-y-4 rounded-lg border bg-muted/20 p-4">
            <div className="grid gap-3 md:grid-cols-3">
                <label className="grid gap-1 text-sm">
                    <span className="font-medium">Sync frequency</span>
                    <select
                        name="frequency"
                        form={`sync-schedule-${operation.id}`}
                        defaultValue={schedule?.frequency ?? 'manual'}
                        className="h-9 rounded-md border bg-background px-3"
                    >
                        <option value="manual">Manual</option>
                        <option value="every_15_minutes">
                            Every 15 minutes
                        </option>
                        <option value="hourly">Hourly</option>
                        <option value="every_6_hours">Every 6 hours</option>
                        <option value="every_12_hours">Every 12 hours</option>
                        <option value="daily">Daily</option>
                    </select>
                </label>
                <label className="grid gap-1 text-sm">
                    <span className="font-medium">Sync strategy</span>
                    <select
                        name="strategy"
                        form={`sync-schedule-${operation.id}`}
                        defaultValue={schedule?.strategy ?? 'full_snapshot'}
                        className="h-9 rounded-md border bg-background px-3"
                    >
                        <option value="full_snapshot">Full snapshot</option>
                        <option value="updated_since">Updated since</option>
                        <option value="cursor">Incremental cursor</option>
                    </select>
                </label>
                <label className="grid gap-1 text-sm">
                    <span className="font-medium">Target dataset</span>
                    <select
                        name="dataset_id"
                        form={`sync-schedule-${operation.id}`}
                        defaultValue={datasetId}
                        className="h-9 rounded-md border bg-background px-3"
                        required
                    >
                        <option value="" disabled>
                            Choose a dataset
                        </option>
                        {datasets.map((dataset) => (
                            <option key={dataset.id} value={dataset.id}>
                                {dataset.name}
                            </option>
                        ))}
                    </select>
                </label>
            </div>

            <div className="grid gap-3 md:grid-cols-2">
                <fieldset className="grid gap-2 rounded-md border p-3">
                    <legend className="px-1 text-sm font-medium">
                        Updated-since checkpoint
                    </legend>
                    <input
                        name="configuration[updated_since][target]"
                        form={`sync-schedule-${operation.id}`}
                        defaultValue={updatedSince.target ?? 'query'}
                        placeholder="query or graphql_variable"
                        className="h-9 rounded-md border bg-background px-3 text-sm"
                    />
                    <input
                        name="configuration[updated_since][name]"
                        form={`sync-schedule-${operation.id}`}
                        defaultValue={updatedSince.name ?? ''}
                        placeholder="Remote parameter or variable"
                        className="h-9 rounded-md border bg-background px-3 text-sm"
                    />
                    <input
                        name="configuration[updated_since][response_path]"
                        form={`sync-schedule-${operation.id}`}
                        defaultValue={updatedSince.response_path ?? ''}
                        placeholder="Response checkpoint path"
                        className="h-9 rounded-md border bg-background px-3 text-sm"
                    />
                    <input
                        name="configuration[updated_since][format]"
                        form={`sync-schedule-${operation.id}`}
                        defaultValue={updatedSince.format ?? 'iso8601'}
                        placeholder="iso8601, unix_seconds, or unix_milliseconds"
                        className="h-9 rounded-md border bg-background px-3 text-sm"
                    />
                    <input
                        name="configuration[updated_since][initial_value]"
                        form={`sync-schedule-${operation.id}`}
                        defaultValue={updatedSince.initial_value ?? ''}
                        placeholder="Optional first-run value"
                        className="h-9 rounded-md border bg-background px-3 text-sm"
                    />
                </fieldset>
                <fieldset className="grid gap-2 rounded-md border p-3">
                    <legend className="px-1 text-sm font-medium">
                        Incremental cursor checkpoint
                    </legend>
                    <input
                        name="configuration[cursor][target]"
                        form={`sync-schedule-${operation.id}`}
                        defaultValue={cursor.target ?? 'query'}
                        placeholder="query or graphql_variable"
                        className="h-9 rounded-md border bg-background px-3 text-sm"
                    />
                    <input
                        name="configuration[cursor][name]"
                        form={`sync-schedule-${operation.id}`}
                        defaultValue={cursor.name ?? ''}
                        placeholder="Remote cursor parameter or variable"
                        className="h-9 rounded-md border bg-background px-3 text-sm"
                    />
                    <input
                        name="configuration[cursor][response_path]"
                        form={`sync-schedule-${operation.id}`}
                        defaultValue={cursor.response_path ?? ''}
                        placeholder="Response checkpoint path"
                        className="h-9 rounded-md border bg-background px-3 text-sm"
                    />
                </fieldset>
            </div>

            <Form
                id={`sync-schedule-${operation.id}`}
                action={syncSchedule.update(args).url}
                method="put"
                options={{ preserveScroll: true }}
                className="flex flex-wrap items-center gap-2"
            >
                {({ errors, processing }) => (
                    <>
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={processing}
                        >
                            {processing ? 'Saving...' : 'Save sync settings'}
                        </Button>
                        <FormErrorSummary errors={errors} />
                    </>
                )}
            </Form>

            <div className="flex flex-wrap items-center gap-2">
                <Form
                    {...runSync.form(args)}
                    options={{ preserveScroll: true }}
                >
                    {({ errors, processing }) => (
                        <>
                            <input
                                type="hidden"
                                name="dataset_id"
                                value={datasetId}
                            />
                            <Button type="submit" disabled={processing}>
                                {processing ? 'Running...' : 'Run now'}
                            </Button>
                            <FormErrorSummary errors={errors} />
                        </>
                    )}
                </Form>
                {schedule?.isEnabled ? (
                    <Form
                        {...syncActions.pause.form(args)}
                        options={{ preserveScroll: true }}
                    >
                        <Button type="submit" variant="outline">
                            Pause
                        </Button>
                    </Form>
                ) : (
                    <Form
                        {...syncActions.resume.form(args)}
                        options={{ preserveScroll: true }}
                    >
                        <Button type="submit" variant="outline">
                            Resume
                        </Button>
                    </Form>
                )}
            </div>
            <div className="grid gap-1 text-xs text-muted-foreground sm:grid-cols-2">
                <span>Next run: {formatDate(schedule?.nextRunAt ?? null)}</span>
                <span>
                    Last success: {formatDate(schedule?.lastSuccessAt ?? null)}
                </span>
                <span>
                    Last failure: {formatDate(schedule?.lastFailureAt ?? null)}
                </span>
                {schedule?.lastError ? <span>{schedule.lastError}</span> : null}
            </div>
        </div>
    );
}

export default function DataSourcesShow({ dataSource }: Props) {
    const { t } = useTranslation();
    const { currentTeam } = usePage().props;
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [sourceFileToDelete, setSourceFileToDelete] =
        useState<SourceFile | null>(null);

    if (!currentTeam) {
        return null;
    }

    return (
        <>
            <Head title={dataSource.name} />

            <h1 className="sr-only">{dataSource.name}</h1>

            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                    <div className="flex items-start gap-3">
                        <Button variant="ghost" size="icon" asChild>
                            <Link
                                href={index(currentTeam.slug).url}
                                aria-label="Back to data sources"
                            >
                                <ArrowLeft />
                            </Link>
                        </Button>
                        <div className="flex items-start gap-3">
                            <div className="mt-1 flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                {dataSource.type === 'rest_api' ||
                                dataSource.type === 'graphql_api' ? (
                                    <Database className="size-5" />
                                ) : (
                                    <FileText className="size-5" />
                                )}
                            </div>
                            <Heading
                                variant="small"
                                title={dataSource.name}
                                description={typeLabel(dataSource.type)}
                            />
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        <Button variant="outline" asChild>
                            <Link
                                href={
                                    edit([currentTeam.slug, dataSource.id]).url
                                }
                            >
                                <Pencil />
                                Edit
                            </Link>
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() => setDeleteDialogOpen(true)}
                            data-test="data-source-show-delete-button"
                        >
                            <Trash2 />
                            Delete
                        </Button>
                    </div>
                </div>

                <div className="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(16rem,1fr)]">
                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between gap-4">
                                <CardTitle>Overview</CardTitle>
                                <Badge
                                    variant={dataSourceStatusVariant(
                                        dataSource.status,
                                    )}
                                >
                                    {statusLabel(dataSource.status)}
                                </Badge>
                                {statusDescription(dataSource.status) ? (
                                    <p className="text-xs text-muted-foreground">
                                        {statusDescription(dataSource.status)}
                                    </p>
                                ) : null}
                            </div>
                        </CardHeader>
                        <CardContent className="grid gap-6 sm:grid-cols-2">
                            <div className="space-y-1">
                                <p className="text-sm text-muted-foreground">
                                    Type
                                </p>
                                <p className="font-medium">
                                    {typeLabel(dataSource.type)}
                                </p>
                            </div>
                            <div className="space-y-1">
                                <p className="text-sm text-muted-foreground">
                                    Last synced
                                </p>
                                <p className="font-medium">
                                    {formatDate(dataSource.lastSyncedAt)}
                                </p>
                            </div>
                            <div className="space-y-1 sm:col-span-2">
                                <p className="text-sm text-muted-foreground">
                                    Non-secret configuration
                                </p>
                                <pre className="overflow-x-auto rounded-lg border bg-muted/30 p-4 text-sm whitespace-pre-wrap">
                                    {JSON.stringify(
                                        dataSource.config ?? {},
                                        null,
                                        2,
                                    )}
                                </pre>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4 text-sm">
                            <div className="space-y-1">
                                <p className="text-muted-foreground">Created</p>
                                <p>{formatDate(dataSource.createdAt)}</p>
                            </div>
                            <div className="space-y-1">
                                <p className="text-muted-foreground">Updated</p>
                                <p>{formatDate(dataSource.updatedAt)}</p>
                            </div>
                        </CardContent>
                    </Card>

                    {dataSource.type === 'rest_api' ||
                    dataSource.type === 'graphql_api' ? (
                        <Card className="lg:col-span-2">
                            <CardHeader>
                                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                                    <div>
                                        <CardTitle>
                                            {t('api_builder.connection_step')}
                                        </CardTitle>
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            {(dataSource.type === 'graphql_api'
                                                ? dataSource.connection
                                                      ?.endpoint
                                                : dataSource.connection
                                                      ?.baseUrl) ?? '—'}{' '}
                                            ·{' '}
                                            {dataSource.connection
                                                ?.credentialsConfigured
                                                ? t(
                                                      'api_builder.configured_placeholder',
                                                  )
                                                : t('api_builder.auth.none')}
                                        </p>
                                    </div>
                                    <div className="flex gap-2">
                                        <Button variant="outline" asChild>
                                            <Link
                                                href={
                                                    (dataSource.type ===
                                                        'graphql_api'
                                                        ? editGraphql
                                                        : editApi)([
                                                        currentTeam.slug,
                                                        dataSource.id,
                                                    ]).url
                                                }
                                            >
                                                {t('common.edit')}
                                            </Link>
                                        </Button>
                                        <Button asChild>
                                            <Link
                                                href={
                                                    (dataSource.apiOperations ?? []).length > 0
                                                        ? editOperation([
                                                              currentTeam.slug,
                                                              dataSource.id,
                                                              dataSource.apiOperations[0].id,
                                                          ]).url
                                                        : createOperation([
                                                              currentTeam.slug,
                                                              dataSource.id,
                                                          ]).url
                                                }
                                            >
                                                {(dataSource.apiOperations ?? []).length > 0
                                                    ? t('common.edit')
                                                    : t('api_builder.operation_title')}
                                            </Link>
                                        </Button>
                                    </div>
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                {(dataSource.apiOperations ?? []).length > 0 ? (
                                    (dataSource.apiOperations ?? []).map(
                                        (operation) => (
                                            <div
                                                key={operation.id}
                                                className="flex flex-col gap-2 rounded-lg border p-4 sm:flex-row sm:items-center sm:justify-between"
                                            >
                                                <div>
                                                    <p className="font-medium">
                                                        {operation.name}
                                                    </p>
                                                    <p className="text-sm text-muted-foreground">
                                                        {operation.method}{' '}
                                                        {operation.path}
                                                    </p>
                                                </div>
                                                <Button variant="outline" size="sm" asChild>
                                                    <Link href={editOperation([
                                                        currentTeam.slug,
                                                        dataSource.id,
                                                        operation.id,
                                                    ]).url}>
                                                        {t('common.edit')}
                                                    </Link>
                                                </Button>
                                                <Badge variant="secondary">
                                                    {operation.executionMode ===
                                                    'write'
                                                        ? t(
                                                              'api_builder.modes.live_write',
                                                          )
                                                        : operation.type ===
                                                            'query'
                                                          ? t(
                                                                'api_builder.modes.synced',
                                                            )
                                                          : t(
                                                                'api_builder.modes.live_read',
                                                            )}
                                                </Badge>
                                                <div className="basis-full">
                                                    <SyncScheduleControls
                                                        currentTeamSlug={
                                                            currentTeam.slug
                                                        }
                                                        dataSource={dataSource}
                                                        operation={operation}
                                                    />
                                                </div>
                                            </div>
                                        ),
                                    )
                                ) : (
                                    <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                                        {t('api_builder.operation_description')}
                                    </p>
                                )}
                            </CardContent>
                        </Card>
                    ) : dataSource.type === 'file' ? (
                        <Card className="lg:col-span-2">
                            <CardHeader>
                                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                                    <div>
                                        <CardTitle>Source files</CardTitle>
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            Upload files here. Importing and
                                            parsing happen in a later workflow.
                                        </p>
                                    </div>
                                    <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                        <Upload className="size-4" />
                                        {dataSource.sourceFiles.length} uploaded
                                    </div>
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-6">
                                <SourceFileUploadForm
                                    currentTeamSlug={currentTeam.slug}
                                    dataSourceId={dataSource.id}
                                />

                                {dataSource.sourceFiles.length > 0 ? (
                                    <div className="divide-y rounded-lg border">
                                        {dataSource.sourceFiles.map(
                                            (sourceFile) => (
                                                <div
                                                    key={sourceFile.id}
                                                    className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
                                                >
                                                    <div className="min-w-0">
                                                        <p className="truncate font-medium">
                                                            {
                                                                sourceFile.originalName
                                                            }
                                                        </p>
                                                        <p className="text-sm text-muted-foreground">
                                                            {sourceFile.extension?.toUpperCase() ??
                                                                sourceFile.mimeType ??
                                                                'Unknown type'}{' '}
                                                            ·{' '}
                                                            {formatFileSize(
                                                                sourceFile.sizeBytes,
                                                            )}{' '}
                                                            ·{' '}
                                                            {formatDate(
                                                                sourceFile.createdAt,
                                                            )}
                                                        </p>
                                                    </div>
                                                    <div className="flex items-center gap-3">
                                                        <Badge variant="secondary">
                                                            {statusLabel(
                                                                sourceFile.status,
                                                            )}
                                                        </Badge>
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() =>
                                                                setSourceFileToDelete(
                                                                    sourceFile,
                                                                )
                                                            }
                                                        >
                                                            <Trash2 />
                                                            Delete
                                                        </Button>
                                                    </div>
                                                </div>
                                            ),
                                        )}
                                    </div>
                                ) : (
                                    <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                                        No source files have been uploaded yet.
                                    </p>
                                )}
                            </CardContent>
                        </Card>
                    ) : (
                        <Card className="lg:col-span-2">
                            <CardHeader>
                                <CardTitle>Source files</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-sm text-muted-foreground">
                                    File uploads are only available for file
                                    data sources. REST API configuration will be
                                    implemented separately.
                                </p>
                            </CardContent>
                        </Card>
                    )}
                </div>
            </div>

            <DeleteDataSourceDialog
                dataSource={dataSource}
                currentTeamSlug={currentTeam.slug}
                open={deleteDialogOpen}
                onOpenChange={setDeleteDialogOpen}
            />
            <DeleteSourceFileDialog
                currentTeamSlug={currentTeam.slug}
                dataSourceId={dataSource.id}
                sourceFile={sourceFileToDelete}
                open={sourceFileToDelete !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setSourceFileToDelete(null);
                    }
                }}
            />
        </>
    );
}
