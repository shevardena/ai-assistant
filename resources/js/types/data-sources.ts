export type DataSourceType = 'file' | 'rest_api' | 'graphql_api';

export type DataSourceStatus =
    'pending' | 'ready' | 'syncing' | 'error' | 'disabled';

export type SourceFileStatus = 'uploaded' | 'processing' | 'ready' | 'failed';

export type SourceFile = {
    id: number;
    originalName: string;
    mimeType: string | null;
    sizeBytes: number | null;
    extension: string | null;
    status: SourceFileStatus;
    createdAt: string | null;
};

export type DataSource = {
    id: number;
    name: string;
    type: DataSourceType;
    status: DataSourceStatus;
    config: Record<string, unknown> | null;
    lastSyncedAt: string | null;
    createdAt: string | null;
    updatedAt: string | null;
    sourceFiles: SourceFile[];
    connection?: ApiConnectionSummary | null;
    apiOperations?: ApiOperationSummary[];
    datasets?: DatasetOption[];
};

export type DatasetOption = { id: number; name: string };

export type ApiConnectionSummary = {
    protocol: 'rest' | 'graphql';
    baseUrl: string | null;
    endpoint: string | null;
    authType: string;
    credentialsConfigured: boolean;
    credentialKeys: string[];
};

export type ApiOperationSummary = {
    id: number;
    key: string;
    name: string;
    type: string;
    executionMode: 'read' | 'write';
    method: string;
    path: string;
    responseMapping: Record<string, unknown> | null;
    timeoutMs: number;
    isEnabled: boolean;
    updatedAt: string | null;
    syncSchedule: ApiOperationSyncSchedule | null;
};

export type ApiOperationSyncSchedule = {
    id: number;
    datasetId: number | null;
    frequency: string;
    strategy: string;
    isEnabled: boolean;
    pausedAt: string | null;
    nextRunAt: string | null;
    lastStartedAt: string | null;
    lastCompletedAt: string | null;
    lastSuccessAt: string | null;
    lastFailureAt: string | null;
    consecutiveFailures: number;
    lastError: string | null;
    configuration: Record<string, unknown>;
};

export type DataSourceSummary = Pick<
    DataSource,
    | 'id'
    | 'name'
    | 'type'
    | 'status'
    | 'lastSyncedAt'
    | 'createdAt'
    | 'updatedAt'
>;
