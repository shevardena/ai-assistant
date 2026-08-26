import type { Paginated } from './bots';

export type NotificationType =
    | 'human_handoff_requested'
    | 'integration_failure'
    | 'data_import_failed'
    | 'lead_captured'
    | 'appointment_booked'
    | 'support_ticket_created'
    | 'action_failed'
    | 'workflow_notification'
    | 'system';

export type NotificationFilter = 'all' | 'unread';

export type TeamNotificationItem = {
    id: string;
    type: NotificationType;
    title: string;
    message: string;
    botName: string | null;
    href: string | null;
    readAt: string | null;
    createdAt: string | null;
};

export type NotificationPageProps = {
    filter: NotificationFilter;
    totalCount: number;
    unreadCount: number;
    notifications: Paginated<TeamNotificationItem>;
};
