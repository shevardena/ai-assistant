import { Link, useForm, useHttp } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    ChevronDown,
    ChevronUp,
    GripVertical,
    LoaderCircle,
    Search,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { show as showDataSource } from '@/routes/data-sources';
import {
    create as createField,
    discovery,
    bulkUpdate,
} from '@/routes/datasets/fields';
import { index as unmappedFields } from '@/routes/datasets/fields/unmapped';
import type {
    Dataset,
    DatasetField,
    DatasetFieldDataType,
    DatasetFieldDiscoveryResponse,
    DatasetFieldMapping,
} from '@/types';

type Props = {
    dataset: Dataset;
    currentTeamSlug: string;
};

type MappingRow = DatasetFieldMapping & {
    configText: string;
};

type BulkFieldPayload = {
    id: number | null;
    source_path: string;
    key: string;
    canonical_name: string | null;
    label: string;
    data_type: DatasetFieldDataType;
    semantic_type: string | null;
    description: string | null;
    is_searchable: boolean;
    is_filterable: boolean;
    is_sortable: boolean;
    is_semantic: boolean;
    is_displayable: boolean;
    normalizer: string | null;
    config: string;
    position: number;
    included: boolean;
};

type QuickFilter =
    'all' | 'searchable' | 'filterable' | 'sortable' | 'displayable';

const fieldTypes: DatasetFieldDataType[] = [
    'string',
    'integer',
    'decimal',
    'boolean',
    'date',
    'datetime',
    'url',
];

const normalizers = ['lowercase', 'percentage', 'currency', 'gb'] as const;
const priceSemanticRoles = ['current_price', 'regular_price', 'discount_percent'] as const;

const quickFilters: Array<{ value: QuickFilter; label: string }> = [
    { value: 'all', label: 'All' },
    { value: 'searchable', label: 'Searchable' },
    { value: 'filterable', label: 'Filterable' },
    { value: 'sortable', label: 'Sortable' },
    { value: 'displayable', label: 'Displayable' },
];

function normalizePath(path: string | null): string {
    return path?.startsWith('$.') ? path.slice(2) : (path ?? '');
}

function rowKey(row: MappingRow): string {
    return row.id === null ? `new:${row.sourcePath}` : `saved:${row.id}`;
}

function compatiblePriceRoles(dataType: DatasetFieldDataType): string[] {
    return dataType === 'decimal'
        ? [...priceSemanticRoles]
        : dataType === 'integer'
            ? ['discount_percent']
            : [];
}

function rowFromField(
    field: DatasetField,
    primaryKeyPath: string | null,
): MappingRow {
    return {
        id: field.id,
        sourcePath: field.sourcePath,
        key: field.key,
        canonicalName: field.canonicalName,
        label: field.label,
        dataType: field.dataType,
        semanticType: field.semanticType,
        description: field.description,
        isSearchable: field.isSearchable,
        isFilterable: field.isFilterable,
        isSortable: field.isSortable,
        isSemantic: field.isSemantic,
        isDisplayable: field.isDisplayable,
        normalizer: field.normalizer,
        config: field.config,
        configText:
            field.config === null ? '' : JSON.stringify(field.config, null, 2),
        position: field.position,
        included: true,
        isExisting: true,
        sampleValues: [],
        confidence: null,
        isPrimaryKey:
            normalizePath(field.sourcePath) === normalizePath(primaryKeyPath),
    };
}

function rowFromDiscovery(
    field: DatasetFieldDiscoveryResponse['fields'][number],
): MappingRow {
    return {
        id: field.id,
        sourcePath: field.source_path,
        key: field.key,
        canonicalName: field.canonical_name,
        label: field.label,
        dataType: field.data_type,
        semanticType: field.semantic_type,
        description: field.description,
        isSearchable: field.is_searchable,
        isFilterable: field.is_filterable,
        isSortable: field.is_sortable,
        isSemantic: field.is_semantic,
        isDisplayable: field.is_displayable,
        normalizer: field.normalizer,
        config: field.config,
        configText:
            field.config === null ? '' : JSON.stringify(field.config, null, 2),
        position: field.position,
        included: field.included,
        isExisting: field.is_existing,
        sampleValues: field.sample_values,
        confidence: field.confidence,
        isPrimaryKey: field.is_primary_key,
    };
}

function rowPayload(row: MappingRow, position: number): BulkFieldPayload {
    return {
        id: row.id,
        source_path: row.sourcePath,
        key: row.key,
        canonical_name: row.canonicalName,
        label: row.label,
        data_type: row.dataType,
        semantic_type: row.semanticType,
        description: row.description,
        is_searchable: row.isSearchable,
        is_filterable: row.isFilterable,
        is_sortable: row.isSortable,
        is_semantic: row.isSemantic,
        is_displayable: row.isDisplayable,
        normalizer: row.normalizer,
        config: row.configText,
        position,
        included: row.included,
    };
}

export default function DatasetFieldMappingEditor({
    dataset,
    currentTeamSlug,
}: Props) {
    const initialRows = dataset.fields.map((field) =>
        rowFromField(field, dataset.primaryKeyPath),
    );
    const [rows, setRows] = useState<MappingRow[]>(initialRows);
    const [selectedSourceFileId, setSelectedSourceFileId] = useState<
        number | null
    >(null);
    const [draggedIndex, setDraggedIndex] = useState<number | null>(null);
    const [expandedRowKey, setExpandedRowKey] = useState<string | null>(null);
    const [searchTerm, setSearchTerm] = useState('');
    const [quickFilter, setQuickFilter] = useState<QuickFilter>('all');
    const availableFiles = dataset.sourceFiles.filter((sourceFile) =>
        ['uploaded', 'ready'].includes(sourceFile.status),
    );
    const discoveryForm = useHttp<
        { source_file_id: number | null },
        DatasetFieldDiscoveryResponse
    >({ source_file_id: null });
    const saveForm = useForm<{ fields: BulkFieldPayload[] }>({
        fields: initialRows.map((row, index) => rowPayload(row, index)),
    });

    const fieldError = (index: number, name: string): string | undefined => {
        const error = (saveForm.errors as Record<string, unknown>)[
            `fields.${index}.${name}`
        ];

        return typeof error === 'string' ? error : undefined;
    };

    const syncRows = (nextRows: MappingRow[]) => {
        const positionedRows = nextRows.map((row, index) => ({
            ...row,
            position: index,
        }));

        setRows(positionedRows);
        saveForm.setData(
            'fields',
            positionedRows.map((row, index) => rowPayload(row, index)),
        );
    };

    const updateRow = (index: number, changes: Partial<MappingRow>) => {
        syncRows(
            rows.map((row, rowIndex) =>
                rowIndex === index ? { ...row, ...changes } : row,
            ),
        );
    };

    const moveRow = (from: number, to: number) => {
        if (to < 0 || to >= rows.length) {
            return;
        }

        const nextRows = [...rows];
        const [movedRow] = nextRows.splice(from, 1);
        nextRows.splice(to, 0, movedRow);
        syncRows(nextRows);
    };

    const discoverFields = (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        discoveryForm.setData('source_file_id', selectedSourceFileId);
        discoveryForm
            .post(discovery.url([currentTeamSlug, dataset.id]))
            .then((response) => {
                setExpandedRowKey(null);
                syncRows(response.fields.map(rowFromDiscovery));
            });
    };

    const saveMappings = (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (
            rows.some((row) => row.isExisting && !row.included) &&
            !window.confirm(
                'Unchecked existing mappings will be removed. Continue?',
            )
        ) {
            return;
        }

        saveForm.put(bulkUpdate.url([currentTeamSlug, dataset.id]), {
            preserveScroll: true,
            preserveState: false,
        });
    };

    useEffect(() => {
        if (!saveForm.isDirty) {
            return;
        }

        const warnBeforeLeaving = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = '';
        };

        window.addEventListener('beforeunload', warnBeforeLeaving);

        return () =>
            window.removeEventListener('beforeunload', warnBeforeLeaving);
    }, [saveForm.isDirty]);

    const normalizedSearch = searchTerm.trim().toLowerCase();
    const visibleRows = rows
        .map((row, index) => ({ row, index }))
        .filter(({ row }) => {
            const matchesSearch =
                normalizedSearch === '' ||
                [row.sourcePath, row.key, row.label].some((value) =>
                    value.toLowerCase().includes(normalizedSearch),
                );
            const matchesFilter =
                quickFilter === 'all' ||
                {
                    searchable: row.isSearchable,
                    filterable: row.isFilterable,
                    sortable: row.isSortable,
                    displayable: row.isDisplayable,
                }[quickFilter];

            return matchesSearch && matchesFilter;
        });
    const initialPayloads = new Map(
        initialRows.map((row, index) => [
            rowKey(row),
            JSON.stringify(rowPayload(row, index)),
        ]),
    );
    const changedCount = rows.filter(
        (row, index) =>
            initialPayloads.get(rowKey(row)) !==
            JSON.stringify(rowPayload(row, index)),
    ).length;
    const mappedCount = rows.filter((row) => row.included).length;
    const searchableCount = rows.filter(
        (row) => row.included && row.isSearchable,
    ).length;
    const filterableCount = rows.filter(
        (row) => row.included && row.isFilterable,
    ).length;

    return (
        <div className="grid gap-4">
            <div className="flex flex-col gap-3 rounded-lg border bg-muted/20 p-3 lg:flex-row lg:items-center lg:justify-between">
                <div className="grid gap-1">
                    <p className="font-medium">Source fields</p>
                    <p className="text-sm text-muted-foreground">
                        Discover up to 50 sample rows without changing saved
                        mappings.
                    </p>
                </div>
                <div className="flex flex-wrap items-end gap-2">
                    {availableFiles.length > 0 ? (
                        <form
                            className="flex items-end gap-2"
                            onSubmit={discoverFields}
                        >
                            <label className="grid gap-1 text-xs text-muted-foreground">
                                Source
                                <select
                                    value={selectedSourceFileId ?? ''}
                                    onChange={(event) =>
                                        setSelectedSourceFileId(
                                            event.target.value === ''
                                                ? null
                                                : Number(event.target.value),
                                        )
                                    }
                                    className="h-8 min-w-48 rounded-md border border-input bg-background px-2 text-sm text-foreground outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                >
                                    <option value="">
                                        Latest available file
                                    </option>
                                    {availableFiles.map((sourceFile) => (
                                        <option
                                            key={sourceFile.id}
                                            value={sourceFile.id}
                                        >
                                            {sourceFile.originalName}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <Button
                                type="submit"
                                size="sm"
                                disabled={discoveryForm.processing}
                            >
                                {discoveryForm.processing ? (
                                    <LoaderCircle className="animate-spin" />
                                ) : (
                                    <Search />
                                )}
                                {discoveryForm.processing
                                    ? 'Discovering...'
                                    : 'Discover all'}
                            </Button>
                        </form>
                    ) : dataset.dataSource?.type === 'file' ? (
                        <Link
                            href={
                                showDataSource([
                                    currentTeamSlug,
                                    dataset.dataSource.id,
                                ]).url
                            }
                            className="text-sm text-primary underline-offset-4 hover:underline"
                        >
                            Upload a source file
                        </Link>
                    ) : (
                        <span className="text-sm text-muted-foreground">
                            File discovery is unavailable for this source.
                        </span>
                    )}
                    <Button variant="outline" size="sm" asChild>
                        <Link
                            href={
                                unmappedFields([currentTeamSlug, dataset.id])
                                    .url
                            }
                        >
                            Add unmapped
                        </Link>
                    </Button>
                    <Button variant="outline" size="sm" asChild>
                        <Link
                            href={
                                createField([currentTeamSlug, dataset.id]).url
                            }
                        >
                            Add manually
                        </Link>
                    </Button>
                </div>
                <InputError message={discoveryForm.errors.source_file_id} />
            </div>

            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div className="relative max-w-sm flex-1">
                    <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                    <input
                        value={searchTerm}
                        onChange={(event) => setSearchTerm(event.target.value)}
                        placeholder="Search fields..."
                        className="h-9 w-full rounded-md border border-input bg-background pr-3 pl-8 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        aria-label="Search fields"
                    />
                </div>
                <div className="flex flex-wrap items-center gap-1">
                    {quickFilters.map((filter) => (
                        <Button
                            key={filter.value}
                            type="button"
                            size="sm"
                            variant={
                                quickFilter === filter.value
                                    ? 'secondary'
                                    : 'ghost'
                            }
                            onClick={() => setQuickFilter(filter.value)}
                        >
                            {filter.label}
                        </Button>
                    ))}
                </div>
            </div>

            <form className="grid gap-3" onSubmit={saveMappings}>
                <div className="overflow-x-auto rounded-lg border">
                    <div className="hidden min-w-[1160px] grid-cols-[2.25rem_minmax(12rem,1.35fr)_minmax(8rem,1fr)_minmax(8rem,1fr)_7rem_repeat(4,4.5rem)_2.5rem] items-center gap-2 border-b bg-muted/30 px-3 py-2 text-xs font-medium text-muted-foreground lg:sticky lg:top-0 lg:z-10 lg:grid">
                        <span aria-hidden="true" />
                        <span>Source</span>
                        <span>Key</span>
                        <span>Label</span>
                        <span>Type</span>
                        <span title="Included in text search">Search</span>
                        <span title="Available for structured filters">
                            Filter
                        </span>
                        <span title="Can be sorted">Sort</span>
                        <span title="Returned to the UI or AI">Display</span>
                        <span aria-hidden="true" />
                    </div>

                    {visibleRows.length > 0 ? (
                        <div className="grid">
                            {visibleRows.map(({ row, index }) => (
                                <MappingRowEditor
                                    key={`${row.id ?? 'new'}-${row.sourcePath}`}
                                    row={row}
                                    index={index}
                                    rowCount={rows.length}
                                    expanded={expandedRowKey === rowKey(row)}
                                    error={(name) => fieldError(index, name)}
                                    onChange={(changes) =>
                                        updateRow(index, changes)
                                    }
                                    onToggleAdvanced={() =>
                                        setExpandedRowKey((current) =>
                                            current === rowKey(row)
                                                ? null
                                                : rowKey(row),
                                        )
                                    }
                                    onMoveUp={() => moveRow(index, index - 1)}
                                    onMoveDown={() => moveRow(index, index + 1)}
                                    onDragStart={() => setDraggedIndex(index)}
                                    onDragOver={(event) =>
                                        event.preventDefault()
                                    }
                                    onDrop={() => {
                                        if (draggedIndex !== null) {
                                            moveRow(draggedIndex, index);
                                        }

                                        setDraggedIndex(null);
                                    }}
                                    onDragEnd={() => setDraggedIndex(null)}
                                />
                            ))}
                        </div>
                    ) : (
                        <p className="p-6 text-center text-sm text-muted-foreground">
                            {rows.length === 0
                                ? 'No mappings yet. Discover fields or add one manually.'
                                : 'No fields match the current search and filter.'}
                        </p>
                    )}
                </div>

                <InputError
                    message={saveForm.errors.fields as string | undefined}
                />
                <p className="text-sm text-muted-foreground">
                    Mapping changes apply on the next import. Re-import this
                    Dataset to refresh existing records.
                </p>

                <div className="sticky bottom-0 z-20 -mx-3 flex flex-col gap-3 border-t bg-background/95 px-3 py-3 shadow-lg backdrop-blur sm:flex-row sm:items-center sm:justify-between">
                    <p className="text-sm text-muted-foreground">
                        {mappedCount} mapped · {searchableCount} searchable ·{' '}
                        {filterableCount} filterable
                        {changedCount > 0 ? (
                            <span className="font-medium text-foreground">
                                {' '}
                                · {changedCount} unsaved change
                                {changedCount === 1 ? '' : 's'}
                            </span>
                        ) : null}
                    </p>
                    <Button
                        type="submit"
                        disabled={!saveForm.isDirty || saveForm.processing}
                    >
                        {saveForm.processing
                            ? 'Saving changes...'
                            : 'Save changes'}
                    </Button>
                </div>
            </form>
        </div>
    );
}

type RowEditorProps = {
    row: MappingRow;
    index: number;
    rowCount: number;
    expanded: boolean;
    error: (name: string) => string | undefined;
    onChange: (changes: Partial<MappingRow>) => void;
    onToggleAdvanced: () => void;
    onMoveUp: () => void;
    onMoveDown: () => void;
    onDragStart: () => void;
    onDragOver: (event: React.DragEvent<HTMLDivElement>) => void;
    onDrop: () => void;
    onDragEnd: () => void;
};

function MappingRowEditor({
    row,
    index,
    rowCount,
    expanded,
    error,
    onChange,
    onToggleAdvanced,
    onMoveUp,
    onMoveDown,
    onDragStart,
    onDragOver,
    onDrop,
    onDragEnd,
}: RowEditorProps) {
    const inputClassName =
        'h-8 min-w-0 rounded-md border border-input bg-transparent px-2 text-sm text-foreground outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';
    const flagFields: Array<
        [
            'isSearchable' | 'isFilterable' | 'isSortable' | 'isDisplayable',
            string,
            string,
        ]
    > = [
        ['isSearchable', 'Search', 'Included in text search'],
        ['isFilterable', 'Filter', 'Available for structured filters'],
        ['isSortable', 'Sort', 'Can be sorted'],
        ['isDisplayable', 'Display', 'Returned to the UI or AI'],
    ];

    return (
        <div
            className={`border-b last:border-b-0 ${expanded ? 'bg-muted/10' : ''}`}
            onDragOver={onDragOver}
            onDrop={onDrop}
        >
            <div className="grid gap-3 px-3 py-3 lg:min-w-[1160px] lg:grid-cols-[2.25rem_minmax(12rem,1.35fr)_minmax(8rem,1fr)_minmax(8rem,1fr)_7rem_repeat(4,4.5rem)_2.5rem] lg:items-center lg:gap-2 lg:py-2">
                <div className="flex items-center justify-between lg:block">
                    <button
                        type="button"
                        draggable
                        onDragStart={onDragStart}
                        onDragEnd={onDragEnd}
                        className="cursor-grab touch-none text-muted-foreground hover:text-foreground active:cursor-grabbing"
                        aria-label={`Drag ${row.label} to reorder`}
                        title="Drag to reorder"
                    >
                        <GripVertical className="size-4" />
                    </button>
                    <div className="flex gap-1 lg:hidden">
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            aria-label={`Move ${row.label} up`}
                            disabled={index === 0}
                            onClick={onMoveUp}
                        >
                            <ArrowUp />
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            aria-label={`Move ${row.label} down`}
                            disabled={index === rowCount - 1}
                            onClick={onMoveDown}
                        >
                            <ArrowDown />
                        </Button>
                    </div>
                </div>

                <div className="grid gap-1">
                    <div className="flex flex-wrap items-center gap-2 text-sm">
                        <label className="flex min-w-0 items-center gap-2">
                            <input
                                type="checkbox"
                                checked={row.included}
                                onChange={(event) =>
                                    onChange({ included: event.target.checked })
                                }
                                className="size-4 shrink-0 rounded border-input accent-primary"
                                aria-label={`Include ${row.sourcePath}`}
                            />
                            <span className="truncate font-medium">
                                {row.sourcePath}
                            </span>
                        </label>
                        {row.isPrimaryKey ? (
                            <span className="rounded bg-primary/10 px-1.5 py-0.5 text-[10px] font-medium text-primary">
                                PRIMARY KEY
                            </span>
                        ) : null}
                        <span className="text-[10px] text-muted-foreground">
                            {row.isExisting ? 'saved' : 'new'}
                        </span>
                    </div>
                    {row.sampleValues.length > 0 ? (
                        <p className="truncate text-xs text-muted-foreground">
                            {row.sampleValues.join(', ')}
                        </p>
                    ) : null}
                    <InputError message={error('source_path')} />
                </div>

                <label className="grid gap-1 text-xs text-muted-foreground">
                    <span className="lg:sr-only">Internal key</span>
                    <input
                        value={row.key}
                        onChange={(event) =>
                            onChange({ key: event.target.value })
                        }
                        className={inputClassName}
                        aria-label={`${row.label} internal key`}
                    />
                    <InputError message={error('key')} />
                </label>

                <label className="grid gap-1 text-xs text-muted-foreground">
                    <span className="lg:sr-only">Label</span>
                    <input
                        value={row.label}
                        onChange={(event) =>
                            onChange({ label: event.target.value })
                        }
                        className={inputClassName}
                        aria-label={`${row.sourcePath} label`}
                    />
                    <InputError message={error('label')} />
                </label>

                <label className="grid gap-1 text-xs text-muted-foreground">
                    <span className="lg:sr-only">Type</span>
                    <select
                        value={row.dataType}
                        onChange={(event) =>
                            onChange({
                                dataType: event.target
                                    .value as DatasetFieldDataType,
                            })
                        }
                        className={inputClassName}
                        aria-label={`${row.label} data type`}
                    >
                        {fieldTypes.map((type) => (
                            <option key={type} value={type}>
                                {type}
                            </option>
                        ))}
                    </select>
                    <InputError message={error('data_type')} />
                </label>

                <div className="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:contents">
                    {flagFields.map(([field, label, description]) => (
                        <label
                            key={field}
                            className="flex items-center gap-2 text-xs text-muted-foreground lg:justify-center"
                            title={description}
                        >
                            <input
                                type="checkbox"
                                checked={row[field]}
                                onChange={(event) =>
                                    onChange({
                                        [field]: event.target.checked,
                                    })
                                }
                                className="size-4 rounded border-input accent-primary"
                                aria-label={`${label}: ${description}`}
                            />
                            <span className="lg:sr-only">{label}</span>
                        </label>
                    ))}
                </div>

                <div className="flex justify-end lg:block">
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        onClick={onToggleAdvanced}
                        aria-expanded={expanded}
                        aria-label={`${expanded ? 'Collapse' : 'Expand'} advanced settings for ${row.label}`}
                        title="Advanced settings"
                    >
                        {expanded ? <ChevronUp /> : <ChevronDown />}
                    </Button>
                </div>
            </div>

            {expanded ? (
                <div className="grid gap-3 border-t bg-muted/20 px-3 py-3 lg:mr-[2.5rem] lg:ml-[2.25rem]">
                    <div className="flex items-center justify-between">
                        <p className="text-sm font-medium">Advanced settings</p>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={onToggleAdvanced}
                        >
                            Collapse
                        </Button>
                    </div>
                    <AdvancedFields
                        row={row}
                        onChange={onChange}
                        error={error}
                    />
                    <div className="flex justify-end gap-1 lg:hidden">
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            aria-label={`Move ${row.label} up`}
                            disabled={index === 0}
                            onClick={onMoveUp}
                        >
                            <ArrowUp />
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            aria-label={`Move ${row.label} down`}
                            disabled={index === rowCount - 1}
                            onClick={onMoveDown}
                        >
                            <ArrowDown />
                        </Button>
                    </div>
                </div>
            ) : null}
        </div>
    );
}

function AdvancedFields({
    row,
    onChange,
    error,
}: {
    row: MappingRow;
    onChange: (changes: Partial<MappingRow>) => void;
    error: (name: string) => string | undefined;
}) {
    return (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <label className="grid gap-1 text-xs text-muted-foreground">
                Canonical name
                <input
                    value={row.canonicalName ?? ''}
                    onChange={(event) =>
                        onChange({ canonicalName: event.target.value || null })
                    }
                    className="h-8 rounded-md border border-input bg-transparent px-2 text-sm text-foreground outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                />
                <InputError message={error('canonical_name')} />
            </label>
            <label className="grid gap-1 text-xs text-muted-foreground">
                Semantic type
                <input
                    value={row.semanticType ?? ''}
                    onChange={(event) =>
                        onChange({ semanticType: event.target.value || null })
                    }
                    className="h-8 rounded-md border border-input bg-transparent px-2 text-sm text-foreground outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                />
                <InputError message={error('semantic_type')} />
            </label>
            <label className="grid gap-1 text-xs text-muted-foreground">
                Price semantic role
                <select
                    value={compatiblePriceRoles(row.dataType).includes(row.semanticType ?? '') ? row.semanticType ?? '' : ''}
                    onChange={(event) =>
                        onChange({ semanticType: event.target.value || null })
                    }
                    className="h-8 rounded-md border border-input bg-background px-2 text-sm text-foreground outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                >
                    <option value="">None</option>
                    {compatiblePriceRoles(row.dataType).map((role) => (
                        <option key={role} value={role}>{role}</option>
                    ))}
                </select>
                <span className="text-[11px]">Use a numeric field for current, regular, or discount pricing.</span>
            </label>
            <label className="grid gap-1 text-xs text-muted-foreground">
                Normalizer
                <select
                    value={row.normalizer ?? ''}
                    onChange={(event) =>
                        onChange({ normalizer: event.target.value || null })
                    }
                    className="h-8 rounded-md border border-input bg-background px-2 text-sm text-foreground outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                >
                    <option value="">None</option>
                    {normalizers.map((normalizer) => (
                        <option key={normalizer} value={normalizer}>
                            {normalizer}
                        </option>
                    ))}
                </select>
                <InputError message={error('normalizer')} />
            </label>
            <label className="grid gap-1 text-xs text-muted-foreground">
                Description
                <input
                    value={row.description ?? ''}
                    onChange={(event) =>
                        onChange({ description: event.target.value || null })
                    }
                    className="h-8 rounded-md border border-input bg-transparent px-2 text-sm text-foreground outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                />
                <InputError message={error('description')} />
            </label>
            <label className="flex items-center gap-2 text-sm text-foreground sm:col-span-2 lg:col-span-4">
                <input
                    type="checkbox"
                    checked={row.isSemantic}
                    onChange={(event) =>
                        onChange({ isSemantic: event.target.checked })
                    }
                    className="size-4 rounded border-input accent-primary"
                />
                <span>Semantic field</span>
            </label>
            <label className="grid gap-1 text-xs text-muted-foreground sm:col-span-2 lg:col-span-4">
                Field configuration JSON
                <textarea
                    value={row.configText}
                    onChange={(event) =>
                        onChange({ configText: event.target.value })
                    }
                    rows={2}
                    className="rounded-md border border-input bg-transparent px-2 py-1 font-mono text-xs text-foreground outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    placeholder="{}"
                />
                <InputError message={error('config')} />
            </label>
        </div>
    );
}
