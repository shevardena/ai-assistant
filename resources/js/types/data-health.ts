import type { Paginated } from './bots';

export type DataHealthRange = 'today' | '7d' | '30d' | '90d';

export type DataHealthState = 'healthy' | 'warning' | 'error' | 'inactive';

export type DataHealthFilters = {
    range: DataHealthRange;
    dataSource: number | null;
    health: 'all' | DataHealthState;
    search: string | null;
};

export type DataHealthSource = {
    id: number;
    name: string;
    type: 'file' | 'rest_api' | 'graphql_api';
    status: string;
};

export type DataHealthIssue = {
    type:
        | 'no_records'
        | 'no_active_records'
        | 'field_zero_coverage'
        | 'recent_import_failures'
        | 'dataset_error'
        | 'source_error';
    severity: 'warning' | 'error';
    message: string;
    field: string | null;
};

export type DataHealthSummary = {
    datasets: number;
    healthy: number;
    warnings: number;
    errors: number;
    inactive: number;
    records: number;
    qualityIssues: number;
};

export type DataHealthFieldCoverage = {
    id: number;
    key: string;
    label: string;
    dataType: string;
    isDisplayable: boolean;
    isSearchable: boolean;
    isFilterable: boolean;
    presentCount: number;
    activeRecords: number;
    coverage: number | null;
    position: number;
};

export type DataHealthDataset = {
    id: number;
    name: string;
    slug: string;
    status: string;
    statusLabel: string;
    health: DataHealthState;
    healthLabel: string;
    dataSource: DataHealthSource | null;
    totalRecords: number;
    activeRecords: number;
    inactiveRecords: number;
    totalFields: number;
    displayableFields: number;
    searchableFields: number;
    filterableFields: number;
    lastSuccessfulImportAt: string | null;
    lastImportAt: string | null;
    lastImportStatus: string | null;
    lastImportRowsWritten: number | null;
    lastImportRowsFailed: number | null;
    lastImportDurationMs: number | null;
    recentFailedImportCount: number;
    recentFailedRowCount: number;
    issueCount: number;
    issues: DataHealthIssue[];
    botCount: number;
    updatedAt: string | null;
};

export type DataHealthDatasetDetail = DataHealthDataset & {
    bots: Array<{
        id: number;
        name: string;
        slug: string;
    }>;
    fieldCoverage: DataHealthFieldCoverage[];
};

export type DataHealthImport = {
    id: number;
    type: string;
    status: string;
    statusLabel: string;
    rowsRead: number;
    rowsWritten: number;
    rowsFailed: number;
    durationMs: number | null;
    errorLabel: string | null;
    startedAt: string | null;
    finishedAt: string | null;
};

export type DataHealthPageProps = {
    filters: DataHealthFilters;
    dataSourceOptions: DataHealthSource[];
    summary: DataHealthSummary;
    datasets: Paginated<DataHealthDataset>;
};

export type DataHealthDetailPageProps = {
    dataset: DataHealthDatasetDetail;
    fieldCoverage: DataHealthFieldCoverage[];
    importHistory: DataHealthImport[];
};
