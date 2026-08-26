import type { DashboardInvitation } from './teams';

export type DashboardRange = 'today' | '7d' | '30d';

export type DashboardMetric = {
    value: number;
    change: number | null;
    url: string;
};

export type DashboardAttentionItem = {
    key:
        | 'handoffs'
        | 'failed_actions'
        | 'failed_imports'
        | 'unassigned'
        | 'integration_failures';
    count: number;
    url: string;
};

export type DashboardHealthItem = {
    state: 'healthy' | 'warning' | 'error';
    [key: string]: string | number;
};
export type DashboardActivityPoint = { date: string; value: number };

export type DashboardConversation = {
    reference: string;
    channel: string;
    title: string;
    status: string;
    assignee: string | null;
    lastActivityAt: string | null;
    url: string;
};

export type DashboardSetupStep = {
    key: string;
    label: string;
    completed: boolean;
    actionUrl: string;
};

export type DashboardProps = {
    pendingInvitations?: DashboardInvitation[];
    team: { name: string; slug: string };
    range: DashboardRange;
    metrics: {
        conversations: DashboardMetric;
        leads: DashboardMetric;
        successfulActions: DashboardMetric;
        handoffs: DashboardMetric;
    };
    attention: DashboardAttentionItem[];
    health: {
        bots: DashboardHealthItem;
        data: DashboardHealthItem;
        integrations: DashboardHealthItem;
        channels: DashboardHealthItem;
    };
    activity: DashboardActivityPoint[];
    recentConversations: DashboardConversation[];
    outcomes: {
        leads: number;
        appointments: number;
        tickets: number;
        completedActions: number;
    };
    improvements: {
        summary: {
            open?: number;
            highPriority?: number;
            customerQuestions?: number;
            dataIntegrationIssues?: number;
        };
        opportunities: Array<{
            type?: string;
            priority?: string;
            title?: string;
        }>;
        url: string;
    };
    setup: {
        isSetup: boolean;
        productionStarted: boolean;
        steps: DashboardSetupStep[];
        url: string;
    };
    bots: { total: number; ready: number; draft: number; url: string };
    channels: { active: number; total: number; url: string };
    billing: {
        plan: { name: string };
        usage: Record<string, { used: number; limit: number | null }>;
    } | null;
    unreadNotifications: number;
    quickActions: Array<{ key: string; url: string }>;
};
