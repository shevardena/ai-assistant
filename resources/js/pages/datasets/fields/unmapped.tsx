import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, Check, LoaderCircle } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { show as showDataset } from '@/routes/datasets';
import { create as createField } from '@/routes/datasets/fields';
import {
    index as unmappedIndex,
    store as unmappedStore,
} from '@/routes/datasets/fields/unmapped';
import type { DatasetFieldDataType, UnmappedDatasetField } from '@/types';

type SourceFileOption = {
    id: number;
    originalName: string;
    extension: string | null;
    status: 'uploaded' | 'ready';
};

type DatasetReference = {
    id: number;
    name: string;
    primaryKeyPath: string | null;
};

type Props = {
    dataset: DatasetReference;
    sourceFiles: SourceFileOption[];
    sourceFile: SourceFileOption | null;
    selectedSourceFileId: number | null;
    fields: UnmappedDatasetField[];
    discoveryError: string | null;
};

type FieldRow = UnmappedDatasetField;

type StorePayload = {
    source_file_id: number | null;
    fields: Array<{
        source_path: string;
        key: string;
        label: string;
        data_type: DatasetFieldDataType;
    }>;
};

const fieldTypes: DatasetFieldDataType[] = [
    'string',
    'integer',
    'decimal',
    'boolean',
    'date',
    'datetime',
    'url',
];

export default function UnmappedDatasetFields({
    dataset,
    sourceFiles,
    sourceFile,
    selectedSourceFileId,
    fields,
    discoveryError,
}: Props) {
    const { currentTeam } = usePage().props;
    const [rows, setRows] = useState<FieldRow[]>(fields);
    const [selectedPaths, setSelectedPaths] = useState<string[]>(
        fields
            .filter((field) => !field.isPrimaryKey)
            .map((field) => field.sourcePath),
    );
    const [sourceSelection, setSourceSelection] = useState(
        selectedSourceFileId?.toString() ?? '',
    );
    const form = useForm<StorePayload>({
        source_file_id: selectedSourceFileId,
        fields: [],
    });

    if (!currentTeam) {
        return null;
    }

    const updateRow = (index: number, changes: Partial<FieldRow>) => {
        setRows((current) =>
            current.map((row, rowIndex) =>
                rowIndex === index ? { ...row, ...changes } : row,
            ),
        );
    };

    const togglePath = (sourcePath: string, checked: boolean) => {
        setSelectedPaths((current) =>
            checked
                ? current.includes(sourcePath)
                    ? current
                    : [...current, sourcePath]
                : current.filter((path) => path !== sourcePath),
        );
    };

    const selectAll = () => {
        setSelectedPaths(rows.map((row) => row.sourcePath));
    };

    const clearAll = () => {
        setSelectedPaths([]);
    };

    const changeSource = (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const options = sourceSelection
            ? { query: { source_file_id: sourceSelection } }
            : undefined;

        router.get(unmappedIndex([currentTeam.slug, dataset.id], options).url);
    };

    const addSelected = (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        const selectedRows = rows.filter((row) =>
            selectedPaths.includes(row.sourcePath),
        );
        const payload: StorePayload = {
            source_file_id: sourceFile?.id ?? null,
            fields: selectedRows.map((row) => ({
                source_path: row.sourcePath,
                key: row.key,
                label: row.label,
                data_type: row.dataType,
            })),
        };

        form.transform(() => payload);
        form.post(unmappedStore([currentTeam.slug, dataset.id]).url, {
            preserveScroll: true,
        });
    };

    const fieldError = (index: number, name: string): string | undefined => {
        const error = (form.errors as Record<string, unknown>)[
            `fields.${index}.${name}`
        ];

        return typeof error === 'string' ? error : undefined;
    };

    const selectedRowIndex = (sourcePath: string): number =>
        rows
            .filter((row) => selectedPaths.includes(row.sourcePath))
            .findIndex((row) => row.sourcePath === sourcePath);

    return (
        <>
            <Head title={`Add unmapped fields to ${dataset.name}`} />
            <h1 className="sr-only">Add unmapped fields to {dataset.name}</h1>

            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex items-start gap-3">
                    <Button variant="ghost" size="icon" asChild>
                        <Link
                            href={
                                showDataset([currentTeam.slug, dataset.id]).url
                            }
                            aria-label="Back to dataset"
                        >
                            <ArrowLeft />
                        </Link>
                    </Button>
                    <Heading
                        variant="small"
                        title="Add unmapped fields"
                        description={`Choose new source fields to add to ${dataset.name}.`}
                    />
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Source file</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-4">
                        {sourceFiles.length > 0 ? (
                            <form
                                className="flex flex-col gap-3 sm:flex-row sm:items-end"
                                onSubmit={changeSource}
                            >
                                <label className="grid flex-1 gap-1 text-sm">
                                    <span className="text-muted-foreground">
                                        Select an uploaded file
                                    </span>
                                    <select
                                        value={sourceSelection}
                                        onChange={(event) =>
                                            setSourceSelection(
                                                event.target.value,
                                            )
                                        }
                                        className="h-9 rounded-md border border-input bg-background px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                    >
                                        <option value="">
                                            Latest available file
                                        </option>
                                        {sourceFiles.map((file) => (
                                            <option
                                                key={file.id}
                                                value={file.id}
                                            >
                                                {file.originalName} (
                                                {file.status})
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <Button type="submit" variant="outline">
                                    Load fields
                                </Button>
                            </form>
                        ) : null}

                        {sourceFile ? (
                            <p className="text-sm text-muted-foreground">
                                Source:{' '}
                                <span className="font-medium text-foreground">
                                    {sourceFile.originalName}
                                </span>
                            </p>
                        ) : (
                            <div className="grid gap-3 rounded-lg border border-dashed p-5 text-sm">
                                <p>
                                    No uploaded source file is available for
                                    field discovery. Upload a file first or add
                                    a field manually.
                                </p>
                                <div className="flex flex-wrap gap-2">
                                    <Button variant="outline" asChild>
                                        <Link
                                            href={
                                                showDataset([
                                                    currentTeam.slug,
                                                    dataset.id,
                                                ]).url
                                            }
                                        >
                                            Back to Dataset
                                        </Link>
                                    </Button>
                                    <Button asChild>
                                        <Link
                                            href={
                                                createField([
                                                    currentTeam.slug,
                                                    dataset.id,
                                                ]).url
                                            }
                                        >
                                            Add field manually
                                        </Link>
                                    </Button>
                                </div>
                            </div>
                        )}

                        {discoveryError ? (
                            <p className="text-sm text-destructive">
                                {discoveryError}
                            </p>
                        ) : null}
                        <InputError message={form.errors.source_file_id} />
                    </CardContent>
                </Card>

                {sourceFile ? (
                    <form className="grid gap-4" onSubmit={addSelected}>
                        <Card>
                            <CardHeader>
                                <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                                    <div>
                                        <CardTitle>
                                            Unmapped source fields
                                        </CardTitle>
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            Only fields not already mapped to
                                            this Dataset are shown.
                                        </p>
                                    </div>
                                    {rows.length > 0 ? (
                                        <div className="flex gap-2">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={selectAll}
                                            >
                                                Select all
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                onClick={clearAll}
                                            >
                                                Clear all
                                            </Button>
                                        </div>
                                    ) : null}
                                </div>
                            </CardHeader>
                            <CardContent>
                                {rows.length > 0 ? (
                                    <div className="grid gap-3">
                                        <div className="hidden grid-cols-[2.5rem_minmax(10rem,1.2fr)_minmax(9rem,1fr)_minmax(9rem,1fr)_8rem] gap-3 border-b px-3 pb-2 text-xs font-medium text-muted-foreground md:grid">
                                            <span>Add</span>
                                            <span>Source field</span>
                                            <span>Internal key</span>
                                            <span>Label</span>
                                            <span>Type</span>
                                        </div>
                                        {rows.map((row, index) => (
                                            <div
                                                key={row.sourcePath}
                                                className="grid gap-3 rounded-lg border p-3 md:grid-cols-[2.5rem_minmax(10rem,1.2fr)_minmax(9rem,1fr)_minmax(9rem,1fr)_8rem] md:items-start md:rounded-none md:border-0 md:border-b"
                                            >
                                                <label className="flex items-center gap-2 text-sm">
                                                    <input
                                                        type="checkbox"
                                                        checked={selectedPaths.includes(
                                                            row.sourcePath,
                                                        )}
                                                        onChange={(event) =>
                                                            togglePath(
                                                                row.sourcePath,
                                                                event.target
                                                                    .checked,
                                                            )
                                                        }
                                                        className="size-4 rounded border-input accent-primary"
                                                    />
                                                    <span className="md:sr-only">
                                                        Add
                                                    </span>
                                                </label>
                                                <div className="grid gap-1">
                                                    <div className="flex flex-wrap items-center gap-2 text-sm font-medium">
                                                        <span>
                                                            {row.sourcePath}
                                                        </span>
                                                        {row.isPrimaryKey ? (
                                                            <span className="rounded bg-primary/10 px-1.5 py-0.5 text-[10px] font-medium text-primary">
                                                                PRIMARY KEY
                                                            </span>
                                                        ) : null}
                                                    </div>
                                                    {row.sampleValues.length >
                                                    0 ? (
                                                        <p className="text-xs text-muted-foreground">
                                                            Sample:{' '}
                                                            {row.sampleValues.join(
                                                                ', ',
                                                            )}
                                                        </p>
                                                    ) : null}
                                                    <InputError
                                                        message={fieldError(
                                                            selectedRowIndex(
                                                                row.sourcePath,
                                                            ),
                                                            'source_path',
                                                        )}
                                                    />
                                                </div>
                                                <label className="grid gap-1 text-xs text-muted-foreground">
                                                    <span className="md:sr-only">
                                                        Internal key
                                                    </span>
                                                    <input
                                                        value={row.key}
                                                        onChange={(event) =>
                                                            updateRow(index, {
                                                                key: event
                                                                    .target
                                                                    .value,
                                                            })
                                                        }
                                                        className="h-8 rounded-md border border-input bg-transparent px-2 text-sm text-foreground outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                                        aria-label={`${row.sourcePath} internal key`}
                                                    />
                                                    <InputError
                                                        message={fieldError(
                                                            selectedRowIndex(
                                                                row.sourcePath,
                                                            ),
                                                            'key',
                                                        )}
                                                    />
                                                </label>
                                                <label className="grid gap-1 text-xs text-muted-foreground">
                                                    <span className="md:sr-only">
                                                        Label
                                                    </span>
                                                    <input
                                                        value={row.label}
                                                        onChange={(event) =>
                                                            updateRow(index, {
                                                                label: event
                                                                    .target
                                                                    .value,
                                                            })
                                                        }
                                                        className="h-8 rounded-md border border-input bg-transparent px-2 text-sm text-foreground outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                                        aria-label={`${row.sourcePath} label`}
                                                    />
                                                    <InputError
                                                        message={fieldError(
                                                            selectedRowIndex(
                                                                row.sourcePath,
                                                            ),
                                                            'label',
                                                        )}
                                                    />
                                                </label>
                                                <label className="grid gap-1 text-xs text-muted-foreground">
                                                    <span className="md:sr-only">
                                                        Type
                                                    </span>
                                                    <select
                                                        value={row.dataType}
                                                        onChange={(event) =>
                                                            updateRow(index, {
                                                                dataType: event
                                                                    .target
                                                                    .value as DatasetFieldDataType,
                                                            })
                                                        }
                                                        className="h-8 rounded-md border border-input bg-background px-2 text-sm text-foreground outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                                        aria-label={`${row.sourcePath} data type`}
                                                    >
                                                        {fieldTypes.map(
                                                            (type) => (
                                                                <option
                                                                    key={type}
                                                                    value={type}
                                                                >
                                                                    {type}
                                                                </option>
                                                            ),
                                                        )}
                                                    </select>
                                                    <InputError
                                                        message={fieldError(
                                                            selectedRowIndex(
                                                                row.sourcePath,
                                                            ),
                                                            'data_type',
                                                        )}
                                                    />
                                                </label>
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <div className="grid gap-3 rounded-lg border border-dashed p-6 text-center text-sm">
                                        <p>
                                            All discovered source fields are
                                            already mapped.
                                        </p>
                                        <Button variant="outline" asChild>
                                            <Link
                                                href={
                                                    showDataset([
                                                        currentTeam.slug,
                                                        dataset.id,
                                                    ]).url
                                                }
                                            >
                                                Back to Dataset
                                            </Link>
                                        </Button>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        <InputError message={form.errors.fields} />
                        <div className="flex flex-wrap gap-3">
                            <Button
                                type="submit"
                                disabled={
                                    selectedPaths.length === 0 ||
                                    form.processing
                                }
                            >
                                {form.processing ? (
                                    <LoaderCircle className="animate-spin" />
                                ) : (
                                    <Check />
                                )}
                                {form.processing
                                    ? 'Adding fields...'
                                    : 'Add selected fields'}
                            </Button>
                            <Button variant="outline" asChild>
                                <Link
                                    href={
                                        showDataset([
                                            currentTeam.slug,
                                            dataset.id,
                                        ]).url
                                    }
                                >
                                    Cancel
                                </Link>
                            </Button>
                        </div>
                    </form>
                ) : null}
            </div>
        </>
    );
}
