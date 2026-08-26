import type { Paginated } from './bots';

export type SupportTicketStatus =
    'open' | 'in_progress' | 'resolved' | 'closed';
export type SupportTicketRange = 'today' | '7d' | '30d' | '90d' | 'all';
export type SupportTicketFilters = {
    bot: string | null;
    range: SupportTicketRange;
    status: SupportTicketStatus | 'all';
    search: string | null;
};
export type SupportTicketBot = { id: number; name: string; slug: string };
export type SupportTicketStatusOption = {
    key: SupportTicketStatus;
    label: string;
};
export type SupportTicketListItem = {
    reference: string;
    status: SupportTicketStatus;
    statusLabel: string;
    subject: string;
    summary: string | null;
    customerName: string | null;
    customerEmail: string | null;
    providerReference: string | null;
    externalUrl: string | null;
    createdAt: string | null;
    bot: SupportTicketBot | null;
    customer: { id: number; name: string } | null;
};
export type SupportTicketDetail = SupportTicketListItem & {
    conversation: { reference: string } | null;
    action: { reference: string; completedAt: string | null } | null;
};
export type SupportTicketSummary = {
    open: number;
    inProgress: number;
    resolved: number;
    closed: number;
    total: number;
};
export type SupportTicketPageProps = {
    filters: SupportTicketFilters;
    botOptions: SupportTicketBot[];
    statusOptions: SupportTicketStatusOption[];
    summary: SupportTicketSummary;
    tickets: Paginated<SupportTicketListItem>;
};
export type SupportTicketDetailPageProps = {
    ticket: SupportTicketDetail;
    statusOptions: SupportTicketStatusOption[];
};
