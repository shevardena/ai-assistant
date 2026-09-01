import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, Database, Layers, Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import DatasetFieldMappingEditor from '@/components/dataset-field-mapping-editor';
import DatasetImportForm from '@/components/dataset-import-form';
import DeleteDatasetDialog from '@/components/delete-dataset-dialog';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    datasetStatusVariant,
    statusDescription,
    statusLabel,
} from '@/lib/status';
import { edit, index } from '@/routes/datasets';
import { index as recordsIndex } from '@/routes/datasets/records';
import type { Dataset, DatasetImportError, DatasetSourceRun } from '@/types';

type Props = {
    dataset: Dataset;
};

function formatDate(value: string | null): string {
    return value
        ? new Intl.DateTimeFormat(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(new Date(value))
        : '—';
}

function runStatusVariant(status: DatasetSourceRun['status']) {
    return status === 'completed' || status === 'partial'
        ? 'default'
        : status === 'failed' || status === 'validation_failed'
          ? 'destructive'
          : 'secondary';
}

function importErrorValue(value: DatasetImportError['raw_value']): string {
    return value === null ? '—' : String(value);
}

function importErrorField(error: DatasetImportError): string {
    return error.source_field ?? error.mapped_key ?? error.field;
}

export default function DatasetsShow({ dataset }: Props) {
    const { t } = useTranslation();
    const { currentTeam } = usePage().props;
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);

    if (!currentTeam) {
        return null;
    }

    return (
        <>
            <Head title={dataset.name} />
            <h1 className="sr-only">{dataset.name}</h1>

            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                    <div className="flex items-start gap-3">
                        <Button variant="ghost" size="icon" asChild>
                            <Link
                                href={index(currentTeam.slug).url}
                                aria-label="Back to datasets"
                            >
                                <ArrowLeft />
                            </Link>
                        </Button>
                        <div className="flex items-start gap-3">
                            <div className="mt-1 flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                <Layers className="size-5" />
                            </div>
                            <Heading
                                variant="small"
                                title={dataset.name}
                                description={`/${dataset.slug}`}
                            />
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        <Button variant="outline" asChild>
                            <Link
                                href={
                                    recordsIndex([currentTeam.slug, dataset.id])
                                        .url
                                }
                            >
                                <Database />
                                {t('common.records')}
                            </Link>
                        </Button>
                        <Button variant="outline" asChild>
                            <Link
                                href={edit([currentTeam.slug, dataset.id]).url}
                            >
                                <Pencil />
                                Edit
                            </Link>
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() => setDeleteDialogOpen(true)}
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
                                    variant={datasetStatusVariant(
                                        dataset.status,
                                    )}
                                >
                                    {statusLabel(dataset.status)}
                                </Badge>
                                {statusDescription(dataset.status) ? (
                                    <p className="text-xs text-muted-foreground">
                                        {statusDescription(dataset.status)}
                                    </p>
                                ) : null}
                            </div>
                        </CardHeader>
                        <CardContent className="grid gap-6 sm:grid-cols-2">
                            <div className="space-y-1">
                                <p className="text-sm text-muted-foreground">
                                    Data Source
                                </p>
                                <p className="font-medium">
                                    {dataset.dataSource?.name ?? 'No source'}
                                </p>
                            </div>
                            <div className="space-y-1">
                                <p className="text-sm text-muted-foreground">
                                    Retrieval mode
                                </p>
                                <p className="font-medium">
                                    {dataset.retrievalMode}
                                </p>
                            </div>
                            <div className="space-y-1">
                                <p className="text-sm text-muted-foreground">
                                    Entity type
                                </p>
                                <p className="font-medium">
                                    {dataset.entityType}
                                </p>
                            </div>
                            <div className="space-y-1">
                                <p className="text-sm text-muted-foreground">
                                    Primary key path
                                </p>
                                <p className="font-medium">
                                    {dataset.primaryKeyPath ?? '—'}
                                </p>
                            </div>
                            <div className="space-y-1 sm:col-span-2">
                                <p className="text-sm text-muted-foreground">
                                    Settings
                                </p>
                                <pre className="overflow-x-auto rounded-lg border bg-muted/30 p-4 text-sm whitespace-pre-wrap">
                                    {JSON.stringify(
                                        dataset.settings ?? {},
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
                                <p>{formatDate(dataset.createdAt)}</p>
                            </div>
                            <div className="space-y-1">
                                <p className="text-muted-foreground">Updated</p>
                                <p>{formatDate(dataset.updatedAt)}</p>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {dataset.dataSource?.type === 'file' ? (
                    <Card>
                        <CardHeader>
                            <CardTitle>Import</CardTitle>
                            <p className="text-sm text-muted-foreground">
                                Select an uploaded source file after configuring
                                the field mappings.
                            </p>
                        </CardHeader>
                        <CardContent>
                            {dataset.fields.length > 0 ? (
                                <DatasetImportForm
                                    currentTeamSlug={currentTeam.slug}
                                    datasetId={dataset.id}
                                    sourceFiles={dataset.sourceFiles}
                                />
                            ) : (
                                <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                                    Add at least one field mapping before
                                    running an import.
                                </p>
                            )}
                        </CardContent>
                    </Card>
                ) : null}

                {dataset.sourceRuns.length > 0 ? (
                    <Card>
                        <CardHeader>
                            <CardTitle>Import history</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="divide-y rounded-lg border">
                                {dataset.sourceRuns.map((sourceRun) => (
                                    <div
                                        key={sourceRun.id}
                                        className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
                                    >
                                        <div>
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Badge
                                                    variant={runStatusVariant(
                                                        sourceRun.status,
                                                    )}
                                                >
                                                    {statusLabel(
                                                        sourceRun.status,
                                                    )}
                                                </Badge>
                                                <span className="text-sm text-muted-foreground">
                                                    {formatDate(
                                                        sourceRun.createdAt,
                                                    )}
                                                </span>
                                            </div>
                                            <p className="mt-1 text-sm">
                                                {sourceRun.rowsWritten} written
                                                · {sourceRun.rowsFailed} failed
                                                · {sourceRun.rowsRead} read
                                            </p>
                                            {sourceRun.error ? (
                                                <p className="mt-1 text-sm text-destructive">
                                                    {sourceRun.error}
                                                </p>
                                            ) : null}
                                            {sourceRun.errorSummary &&
                                            sourceRun.errorSummary.samples.length > 0 ? (
                                                <details className="mt-3 rounded-lg border bg-muted/20 p-3 text-sm">
                                                    <summary className="cursor-pointer font-medium">
                                                        View errors ({sourceRun.errorSummary.totalErrors})
                                                    </summary>
                                                    <div className="mt-3 grid gap-3">
                                                        {Object.entries(
                                                            sourceRun.errorSummary.errorTypes,
                                                        ).length === 1 ? (
                                                            <p className="text-muted-foreground">
                                                                {sourceRun.rowsFailed} row
                                                                {sourceRun.rowsFailed === 1
                                                                    ? ''
                                                                    : 's'}{' '}
                                                                failed because{' '}
                                                                <code className="rounded bg-muted px-1 py-0.5">
                                                                    {importErrorField(
                                                                        sourceRun.errorSummary.samples[0],
                                                                    )}
                                                                </code>{' '}
                                                                {sourceRun.errorSummary.samples[0].message}{' '}
                                                                (received{' '}
                                                                <code className="rounded bg-muted px-1 py-0.5">
                                                                    {importErrorValue(
                                                                        sourceRun.errorSummary.samples[0].raw_value,
                                                                    )}
                                                                </code>
                                                                ) · code{' '}
                                                                <code className="rounded bg-muted px-1 py-0.5">
                                                                    {Object.keys(
                                                                        sourceRun.errorSummary.errorTypes,
                                                                    )[0]}
                                                                </code>
                                                                .
                                                            </p>
                                                        ) : null}
                                                        <div className="overflow-x-auto rounded-md border">
                                                            <table className="w-full text-left text-xs">
                                                                <thead className="border-b bg-muted/30">
                                                                    <tr>
                                                                        <th className="px-3 py-2 font-medium">Row</th>
                                                                        <th className="px-3 py-2 font-medium">Field</th>
                                                                        <th className="px-3 py-2 font-medium">Value</th>
                                                                        <th className="px-3 py-2 font-medium">Reason</th>
                                                                    </tr>
                                                                </thead>
                                                                <tbody className="divide-y">
                                                                    {sourceRun.errorSummary.samples.map(
                                                                        (error, errorIndex) => (
                                                                            <tr key={`${error.row}-${errorIndex}`}>
                                                                                <td className="whitespace-nowrap px-3 py-2 align-top">
                                                                                    {error.row}
                                                                                </td>
                                                                                <td className="min-w-40 px-3 py-2 align-top">
                                                                                    <p>{importErrorField(error)}</p>
                                                                                    {error.mapped_key &&
                                                                                    error.source_field ? (
                                                                                        <p className="text-muted-foreground">
                                                                                            → {error.mapped_key}
                                                                                        </p>
                                                                                    ) : null}
                                                                                </td>
                                                                                <td className="max-w-56 break-words px-3 py-2 align-top">
                                                                                    {importErrorValue(error.raw_value)}
                                                                                </td>
                                                                                <td className="min-w-64 px-3 py-2 align-top">
                                                                                    <p>{error.message}</p>
                                                                                    <p className="text-muted-foreground">
                                                                                        {error.stage} · {error.error_code}
                                                                                    </p>
                                                                                </td>
                                                                            </tr>
                                                                        ),
                                                                    )}
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                    </div>
                                                </details>
                                            ) : null}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                ) : null}

                <Card>
                    <CardHeader>
                        <CardTitle>Field Mapping</CardTitle>
                        <p className="text-sm text-muted-foreground">
                            {dataset.fields.length} mapped{' '}
                            {dataset.fields.length === 1 ? 'field' : 'fields'}.
                            Discover source fields, review the suggestions, and
                            save the mappings together.
                        </p>
                    </CardHeader>
                    <CardContent>
                        <DatasetFieldMappingEditor
                            dataset={dataset}
                            currentTeamSlug={currentTeam.slug}
                        />
                    </CardContent>
                </Card>
            </div>

            <DeleteDatasetDialog
                dataset={dataset}
                currentTeamSlug={currentTeam.slug}
                open={deleteDialogOpen}
                onOpenChange={setDeleteDialogOpen}
            />
        </>
    );
}
