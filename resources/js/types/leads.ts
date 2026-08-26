import type { Paginated } from './bots';
import type { TaskListItem } from './tasks';

export type LeadStatus = 'new' | 'contacted' | 'qualified' | 'won' | 'lost';

export type LeadRange = 'today' | '7d' | '30d' | '90d' | 'all';

export type LeadFilters = {
    bot: string | null;
    range: LeadRange;
    status: LeadStatus | 'all';
    search: string | null;
};

export type LeadBot = {
    id: number;
    name: string;
    slug: string;
};

export type LeadStatusOption = {
    key: LeadStatus;
    label: string;
};

export type LeadListItem = {
    id: number;
    reference: string;
    name: string;
    email: string | null;
    phone: string | null;
    status: LeadStatus;
    statusLabel: string;
    source: 'widget' | 'conversation' | 'api' | 'preview';
    sourceLabel: string;
    capturedAt: string | null;
    bot: LeadBot | null;
    customer: { id: number; name: string } | null;
};

export type LeadDetail = LeadListItem & {
    interestSummary: string | null;
    providerReference: string | null;
    conversation: { reference: string } | null;
    action: { reference: string } | null;
    deals: { id: number; title: string; status: string; valueAmount: string | null; currency: string }[];
};

export type LeadSummary = {
    total: number;
    new: number;
    contacted: number;
    qualified: number;
    won: number;
    lost: number;
};

export type LeadPageProps = {
    filters: LeadFilters;
    botOptions: LeadBot[];
    statusOptions: LeadStatusOption[];
    summary: LeadSummary;
    leads: Paginated<LeadListItem>;
};

export type LeadDetailPageProps = {
    lead: LeadDetail;
    tasks: TaskListItem[];
    statusOptions: LeadStatusOption[];
};
