import type { Paginated } from './bots';

export type TaskStatus = 'open' | 'in_progress' | 'completed' | 'cancelled';
export type TaskPriority = 'low' | 'normal' | 'high' | 'urgent';

export type TaskRelation = {
    id: number;
    name?: string | null;
    title?: string | null;
    reference?: string | null;
};

export type TaskListItem = {
    id: number;
    title: string;
    description: string | null;
    status: TaskStatus;
    priority: TaskPriority;
    assignee: { id: number; name: string } | null;
    creator: { id: number; name: string } | null;
    dueAt: string | null;
    completedAt: string | null;
    source: string;
    customer: TaskRelation | null;
    lead: TaskRelation | null;
    deal: TaskRelation | null;
    conversation: TaskRelation | null;
    ticket: TaskRelation | null;
    appointment: TaskRelation | null;
    updatedAt: string | null;
    overdue: boolean;
    relatedTo: { type: string; label: string; id: number } | null;
};

export type TaskFilters = {
    scope: 'my' | 'all' | 'overdue' | 'upcoming' | 'completed';
    status: TaskStatus | null;
    priority: TaskPriority | null;
    assignedUserId: number | null;
    customerId: number | null;
    leadId: number | null;
    dealId: number | null;
    dueFrom: string | null;
    dueTo: string | null;
    search: string | null;
};

export type TaskOption = { id: number; name: string; email?: string | null; reference?: string; title?: string; startsAt?: string | null };
export type TaskMetrics = { open: number; mine: number; overdue: number; upcoming: number; completed: number };

export type TaskPageProps = {
    filters: TaskFilters;
    tasks: Paginated<TaskListItem>;
    metrics: TaskMetrics;
    assigneeOptions: TaskOption[];
    customerOptions: TaskOption[];
    leadOptions: TaskOption[];
    dealOptions: TaskOption[];
};

export type TaskFormProps = {
    prefill?: Record<string, string | number | null>;
    assigneeOptions: TaskOption[];
    customerOptions: TaskOption[];
    leadOptions: TaskOption[];
    dealOptions: TaskOption[];
    conversationOptions: TaskOption[];
    ticketOptions: TaskOption[];
    appointmentOptions: TaskOption[];
    statusOptions: { key: 'open' | 'in_progress'; label: string }[];
    priorityOptions: { key: TaskPriority; label: string }[];
};

export type TaskDetailPageProps = TaskFormProps & {
    task: TaskListItem;
    activity: { type: string; title: string; description: string | null; actor: string | null; timestamp: string | null }[];
};
