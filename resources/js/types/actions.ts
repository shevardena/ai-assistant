import type { Paginated } from './bots';

export type ActionHistoryRange = 'all' | 'today' | '7d' | '30d' | '90d';

export type ActionHistoryStatus =
    | 'pending_confirmation'
    | 'confirmed'
    | 'executing'
    | 'completed'
    | 'failed'
    | 'cancelled';

export type ActionHistoryFilters = {
    bot: string | null;
    range: ActionHistoryRange;
    action: string | null;
    status: ActionHistoryStatus | null;
    search: string | null;
};

export type ActionHistoryOption = {
    key: string;
    label: string;
};

export type ActionHistoryBot = {
    id: number;
    name: string;
    slug: string;
};

export type ActionHistoryItem = {
    actionReference: string;
    tool: string;
    label: string;
    status: ActionHistoryStatus;
    statusLabel: string;
    bot: ActionHistoryBot;
    conversationReference: string | null;
    createdAt: string | null;
    completedAt: string | null;
    durationMs: number | null;
    errorSummary: string | null;
};

export type ActionHistorySummary = {
    total: number;
    completed: number;
    failed: number;
    cancelled: number;
    pending: number;
    successRate: number | null;
};

export type ActionSafeResult = {
    summary: string | null;
};

export type ActionLifecycleStep = {
    key: string;
    label: string;
    at: string | null;
};

export type ActionHistoryDetail = ActionHistoryItem & {
    confirmedAt: string | null;
    startedAt: string | null;
    failedAt: string | null;
    cancelledAt: string | null;
    result: ActionSafeResult;
    conversation: {
        reference: string;
        source: 'widget' | 'preview' | 'conversation';
    } | null;
    lifecycle: ActionLifecycleStep[];
};

export type ActionHistoryPageProps = {
    filters: ActionHistoryFilters;
    botOptions: ActionHistoryBot[];
    actionOptions: ActionHistoryOption[];
    statusOptions: ActionHistoryOption[];
    summary: ActionHistorySummary;
    actions: Paginated<ActionHistoryItem>;
};

export type ActionHistoryDetailPageProps = {
    action: ActionHistoryDetail;
};
