export type IntegrationHealthRange = 'today' | '7d' | '30d' | '90d';

export type IntegrationHealthState =
    'healthy' | 'warning' | 'error' | 'inactive';

export type IntegrationHealthFilters = {
    range: IntegrationHealthRange;
    dataSource: number | null;
    health: 'all' | IntegrationHealthState;
};

export type IntegrationHealthSummary = {
    integrations: number;
    healthy: number;
    warnings: number;
    errors: number;
    inactive: number;
    recentFailures: number;
};

export type IntegrationDataSourceOption = {
    id: number;
    name: string;
};

export type IntegrationHealthDataset = {
    id: number;
    name: string;
    slug: string;
};

export type IntegrationHealthBot = {
    id: number;
    name: string;
    slug: string;
    enabled?: boolean;
};

export type IntegrationHealthItem = {
    id: number;
    name: string;
    type: 'file' | 'rest_api' | 'graphql_api';
    status: string;
    statusLabel: string;
    health: IntegrationHealthState;
    healthLabel: string;
    lastSyncedAt: string | null;
    lastRunAt: string | null;
    lastSuccessfulRunAt: string | null;
    lastFailureAt: string | null;
    lastFailureLabel: string | null;
    recentFailureCount: number;
    rowsRead: number | null;
    rowsWritten: number | null;
    rowsFailed: number | null;
    lastRunDurationMs: number | null;
    datasets: IntegrationHealthDataset[];
    bots: IntegrationHealthBot[];
    operationCount: number;
    readTelemetryAvailable: boolean;
};

export type IntegrationOperationMetric = {
    id: number;
    key: string;
    name: string;
    source: {
        id: number;
        name: string;
    };
    mode: 'read' | 'write';
    enabled: boolean;
    bots: IntegrationHealthBot[];
    telemetryAvailable: boolean;
    telemetryMessage: string | null;
    calls: number | null;
    successes: number | null;
    failures: number | null;
    failureRate: number | null;
    averageDurationMs: number | null;
    lastSuccessAt: string | null;
    lastFailureAt: string | null;
};

export type IntegrationFailureItem = {
    id: number;
    kind: 'import' | 'action';
    source: {
        id: number;
        name: string;
    };
    dataset: IntegrationHealthDataset | null;
    operation: {
        id: number;
        name: string;
        key: string;
    } | null;
    bot: IntegrationHealthBot | null;
    status: string;
    errorCode: string;
    errorLabel: string;
    at: string | null;
    actionReference: string | null;
};

export type IntegrationHealthPageProps = {
    filters: IntegrationHealthFilters;
    dataSourceOptions: IntegrationDataSourceOption[];
    healthOptions: Array<{
        key: IntegrationHealthState;
        label: string;
    }>;
    summary: IntegrationHealthSummary;
    items: IntegrationHealthItem[];
    operations: IntegrationOperationMetric[];
    recentFailures: IntegrationFailureItem[];
};

export type IntegrationHealthDetailProps = {
    dataSource: IntegrationHealthItem;
    operations: IntegrationOperationMetric[];
    recentFailures: IntegrationFailureItem[];
};
