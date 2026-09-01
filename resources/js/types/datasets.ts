import type { DataSourceStatus, DataSourceType } from './data-sources';

export type DatasetStatus =
    'preparing' | 'processing' | 'ready' | 'indexing' | 'error';

export type DatasetRetrievalMode = 'indexed' | 'live' | 'hybrid';

export type DatasetDataSource = {
    id: number;
    name: string;
    type: DataSourceType;
    status: DataSourceStatus;
};

export type DatasetFieldDataType =
    'string' | 'integer' | 'decimal' | 'boolean' | 'date' | 'datetime' | 'url';

export type DatasetSourceFileStatus =
    'uploaded' | 'processing' | 'ready' | 'failed';

export type DatasetSourceFile = {
    id: number;
    originalName: string;
    mimeType: string | null;
    sizeBytes: number | null;
    extension: string | null;
    status: DatasetSourceFileStatus;
    createdAt: string | null;
};

export type DatasetSourceRunStatus =
    'pending' | 'running' | 'completed' | 'partial' | 'validation_failed' | 'failed';

export type DatasetImportError = {
    row: number;
    field: string;
    stage: string;
    source_field: string | null;
    mapped_key: string | null;
    raw_value: string | number | boolean | null;
    normalized_value: string | number | boolean | null;
    error_code: string;
    message: string;
};

export type DatasetImportErrorSummary = {
    totalErrors: number;
    errorTypes: Record<string, number>;
    samples: DatasetImportError[];
};

export type DatasetSourceRun = {
    id: number;
    type: string;
    status: DatasetSourceRunStatus;
    rowsRead: number;
    rowsWritten: number;
    rowsFailed: number;
    error: string | null;
    errorSummary: DatasetImportErrorSummary | null;
    startedAt: string | null;
    finishedAt: string | null;
    createdAt: string | null;
};

export type DatasetField = {
    id: number;
    sourcePath: string;
    key: string;
    canonicalName: string | null;
    label: string;
    dataType: DatasetFieldDataType;
    semanticType: string | null;
    description: string | null;
    isSearchable: boolean;
    isFilterable: boolean;
    isSortable: boolean;
    isSemantic: boolean;
    isDisplayable: boolean;
    normalizer: string | null;
    config: Record<string, unknown> | null;
    position: number;
    createdAt?: string | null;
    updatedAt?: string | null;
};

export type DatasetFieldMapping = {
    id: number | null;
    sourcePath: string;
    key: string;
    canonicalName: string | null;
    label: string;
    dataType: DatasetFieldDataType;
    semanticType: string | null;
    description: string | null;
    isSearchable: boolean;
    isFilterable: boolean;
    isSortable: boolean;
    isSemantic: boolean;
    isDisplayable: boolean;
    normalizer: string | null;
    config: Record<string, unknown> | null;
    position: number;
    included: boolean;
    isExisting: boolean;
    sampleValues: string[];
    confidence: 'low' | 'medium' | 'high' | null;
    isPrimaryKey: boolean;
};

export type UnmappedDatasetField = {
    sourcePath: string;
    key: string;
    label: string;
    dataType: DatasetFieldDataType;
    isSearchable: boolean;
    isFilterable: boolean;
    isSortable: boolean;
    isDisplayable: boolean;
    sampleValues: string[];
    confidence: 'low' | 'medium' | 'high';
    isPrimaryKey: boolean;
};

export type DatasetFieldDiscoveryResponse = {
    source_file: {
        id: number;
        original_name: string;
        extension: string | null;
        status: DatasetSourceFileStatus;
    };
    fields: Array<{
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
        config: Record<string, unknown> | null;
        position: number;
        included: boolean;
        is_existing: boolean;
        sample_values: string[];
        confidence: 'low' | 'medium' | 'high' | null;
        is_primary_key: boolean;
    }>;
    sample_row_limit: number;
};

export type Dataset = {
    id: number;
    name: string;
    slug: string;
    entityType: string;
    retrievalMode: DatasetRetrievalMode;
    primaryKeyPath: string | null;
    status: DatasetStatus;
    settings: Record<string, unknown> | null;
    dataSource: DatasetDataSource | null;
    fields: DatasetField[];
    sourceFiles: DatasetSourceFile[];
    sourceRuns: DatasetSourceRun[];
    createdAt: string | null;
    updatedAt: string | null;
};

export type DatasetSummary = Pick<
    Dataset,
    | 'id'
    | 'name'
    | 'slug'
    | 'entityType'
    | 'retrievalMode'
    | 'status'
    | 'dataSource'
    | 'createdAt'
    | 'updatedAt'
>;

export type DatasetRecordField = {
    label: string;
    dataType: DatasetFieldDataType;
    value: unknown;
};

export type DatasetRecord = {
    id: number;
    externalId: string;
    origin: 'manual' | 'file_import' | 'rest_api' | 'graphql_api';
    isActive: boolean;
    createdAt: string | null;
    updatedAt: string | null;
    sourceUpdatedAt: string | null;
    values: Record<string, DatasetRecordField>;
    raw?: Record<string, unknown>;
};

export type DatasetRecordFieldDefinition = {
    id: number;
    key: string;
    label: string;
    dataType: DatasetFieldDataType;
    description: string | null;
    isDisplayable: boolean;
    config: Record<string, unknown> | null;
};

export type DatasetDataSourceOption = DatasetDataSource;
