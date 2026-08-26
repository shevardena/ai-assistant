export type ImprovementType =
    | 'knowledge_gap'
    | 'zero_result_search'
    | 'dataset_quality'
    | 'integration_failure'
    | 'action_failure';

export type ImprovementCategory =
    | 'customer_questions'
    | 'search'
    | 'data'
    | 'integrations'
    | 'actions'
    | 'configuration';

export type ImprovementPriority = 'high' | 'medium' | 'low';

export type ImprovementFilters = {
    bot: string | null;
    range: 'today' | '7d' | '30d' | '90d';
    type: ImprovementCategory | 'all';
    priority: ImprovementPriority | 'all';
};

export type ImprovementEvidence = {
    label: string;
    value: string;
};

export type ImprovementOpportunity = {
    type: ImprovementType;
    category: ImprovementCategory;
    priority: ImprovementPriority;
    title: string;
    description: string;
    recommendation: string;
    bot: {
        id: number;
        name: string;
        slug: string;
    } | null;
    evidence: ImprovementEvidence[];
    destination: {
        label: string;
        url: string;
    };
    lastSeenAt: string | null;
};

export type ImprovementSummary = {
    open: number;
    highPriority: number;
    customerQuestions: number;
    dataIntegrationIssues: number;
};

export type ImprovementCenterPageProps = {
    filters: ImprovementFilters;
    botOptions: Array<{
        id: number;
        name: string;
        slug: string;
    }>;
    typeOptions: Array<{
        key: ImprovementCategory;
        label: string;
    }>;
    priorityOptions: Array<{
        key: ImprovementPriority;
        label: string;
    }>;
    summary: ImprovementSummary;
    opportunities: ImprovementOpportunity[];
    total: number;
};
