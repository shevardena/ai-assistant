export type AnalyticsRange = 'today' | '7d' | '30d' | '90d';

export type AnalyticsFilters = {
    range: AnalyticsRange;
    bot: string | null;
};

export type AnalyticsSummary = {
    conversations: number;
    visitors: number;
    messages: number;
    searches: number;
    zeroResultSearches: number;
    averageResultCount: number | null;
    actionsProposed: number;
    completedActions: number;
    failedActions: number;
    cancelledActions: number;
    actionSuccessRate: number | null;
    leadsCaptured: number;
    supportTickets: number;
    appointmentsBooked: number;
    addToCart: number;
};

export type AnalyticsTimeseriesPoint = {
    date: string;
    value: number;
};

export type AnalyticsCapabilityMetric = {
    key: string;
    label: string;
    count: number;
};

export type AnalyticsActionMetric = {
    key: string;
    label: string;
    completed: number;
    failed: number;
    cancelled: number;
};

export type AnalyticsBotOption = {
    name: string;
    slug: string;
};

export type AnalyticsBotRow = AnalyticsBotOption & {
    conversations: number;
    messages: number;
    searches: number;
    completedActions: number;
};

export type AnalyticsPageProps = {
    filters: AnalyticsFilters;
    botOptions: AnalyticsBotOption[];
    summary: AnalyticsSummary;
    timeseries: {
        conversations: AnalyticsTimeseriesPoint[];
    };
    capabilities: AnalyticsCapabilityMetric[];
    actions: AnalyticsActionMetric[];
    bots: AnalyticsBotRow[];
};
