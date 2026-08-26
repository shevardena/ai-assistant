import type { Paginated } from './bots';

export type AppointmentStatus =
    'scheduled' | 'completed' | 'no_show' | 'cancelled';
export type AppointmentRange = 'today' | '7d' | '30d' | '90d' | 'all';
export type AppointmentFilters = {
    bot: string | null;
    range: AppointmentRange;
    status: AppointmentStatus | 'all';
};
export type AppointmentBot = { id: number; name: string; slug: string };
export type AppointmentStatusOption = { key: AppointmentStatus; label: string };
export type AppointmentListItem = {
    reference: string;
    status: AppointmentStatus;
    statusLabel: string;
    startsAt: string | null;
    endsAt: string | null;
    timezone: string | null;
    customerName: string | null;
    customerEmail: string | null;
    customerPhone: string | null;
    providerReference: string | null;
    createdAt: string | null;
    bot: AppointmentBot | null;
    customer: { id: number; name: string } | null;
};
export type AppointmentDetail = AppointmentListItem & {
    conversation: { reference: string } | null;
    action: { reference: string; completedAt: string | null } | null;
};
export type AppointmentSummary = {
    upcoming: number;
    today: number;
    completed: number;
    noShow: number;
    cancelled: number;
};
export type AppointmentPageProps = {
    filters: AppointmentFilters;
    botOptions: AppointmentBot[];
    statusOptions: AppointmentStatusOption[];
    summary: AppointmentSummary;
    appointments: Paginated<AppointmentListItem>;
};
export type AppointmentDetailPageProps = {
    appointment: AppointmentDetail;
    statusOptions: AppointmentStatusOption[];
};
