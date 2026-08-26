import type { Paginated } from './bots';
import type { TaskListItem } from './tasks';

export type DealStatus = 'open' | 'won' | 'lost';
export type DealStage = {
    id: number;
    name: string;
    sortOrder?: number;
    semanticType: 'open' | 'won' | 'lost';
    probability?: number | null;
};
export type DealPipeline = {
    id: number;
    name: string;
    isDefault: boolean;
    stages: DealStage[];
};
export type DealListItem = {
    id: number;
    title: string;
    customer: { id: number; name: string; email?: string | null } | null;
    pipeline: { id: number; name: string };
    stage: DealStage;
    stageId: number;
    status: DealStatus;
    valueAmount: string | null;
    currency: string;
    owner: { id: number; name: string } | null;
    expectedCloseDate: string | null;
    overdue: boolean;
    updatedAt: string | null;
};
export type DealMetrics = {
    openCount: number;
    wonCount: number;
    lostCount: number;
    overdueCount: number;
    closeSoonCount: number;
    winRate: number | null;
    byCurrency: { currency: string; openValue: string; wonValue: string }[];
};
export type DealFilters = {
    pipelineId: number | null;
    stageId: number | null;
    status: DealStatus | 'all';
    ownerUserId: number | null;
    search: string | null;
    expectedClose: 'overdue' | '30d' | null;
};
export type DealIndexPageProps = {
    view: 'board' | 'list';
    filters: DealFilters;
    pipelines: DealPipeline[];
    ownerOptions: { id: number; name: string }[];
    metrics: DealMetrics;
    stages: DealStage[];
    deals: DealListItem[] | Paginated<DealListItem>;
};
export type DealDetail = DealListItem & {
    description: string | null;
    lead: { id: number; reference: string; name: string | null } | null;
    probability: number | null;
    lostReason: string | null;
    wonAt: string | null;
    lostAt: string | null;
    activities: {
        type: string;
        title: string;
        description: string | null;
        actor: string | null;
        timestamp: string | null;
    }[];
};
export type DealDetailPageProps = {
    deal: DealDetail;
    tasks: TaskListItem[];
    pipelineOptions: DealPipeline[];
    customerOptions: { id: number; name: string; email: string | null }[];
    leadOptions: {
        id: number;
        reference: string;
        name: string;
        email: string | null;
        customerId: number | null;
    }[];
    currencyOptions: string[];
    selectedPipelineId: number | null;
};
