import { router } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import type { TaskFormProps, TaskListItem } from '@/types';

type TaskFormMode = 'create' | 'edit';

type Props = TaskFormProps & {
    mode: TaskFormMode;
    action: string;
    task?: TaskListItem;
};

function value(task: TaskListItem | undefined, prefill: Record<string, string | number | null> | undefined, key: string, fallback = ''): string {
    const taskValue = task ? ({
        title: task.title,
        description: task.description,
        priority: task.priority,
        assigned_user_id: task.assignee?.id,
        due_at: task.dueAt?.slice(0, 16),
        customer_id: task.customer?.id,
        lead_id: task.lead?.id,
        deal_id: task.deal?.id,
        conversation_id: task.conversation?.id,
        support_ticket_id: task.ticket?.id,
        appointment_id: task.appointment?.id,
    } as Record<string, string | number | null>)[key] : undefined;

    return String(taskValue ?? prefill?.[key] ?? fallback);
}

export default function TaskForm({ mode, action, task, prefill, assigneeOptions, customerOptions, leadOptions, dealOptions, conversationOptions, ticketOptions, appointmentOptions, statusOptions, priorityOptions }: Props) {
    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const data = new FormData(event.currentTarget);

        if (mode === 'create') {
            router.post(action, data);
        } else {
            router.patch(action, data, { preserveScroll: true });
        }
    };

    const select = (name: string, label: string, options: { id: number; name?: string; title?: string; reference?: string }[], selected: string) => (
        <label className="grid gap-2 text-sm">
            {label}
            <select name={name} defaultValue={selected} className="rounded-lg border bg-transparent px-3 py-2">
                <option value="">None</option>
                {options.map((option) => <option key={option.id} value={option.id}>{option.name ?? option.title ?? option.reference ?? option.id}</option>)}
            </select>
        </label>
    );

    return <form onSubmit={submit} className="grid gap-4 rounded-xl border p-4 md:p-6">
        <label className="grid gap-2 text-sm">Title<input name="title" required defaultValue={value(task, prefill, 'title')} className="rounded-lg border bg-transparent px-3 py-2" /></label>
        <label className="grid gap-2 text-sm">Description<textarea name="description" defaultValue={value(task, prefill, 'description')} className="min-h-28 rounded-lg border bg-transparent px-3 py-2" /></label>
        <div className="grid gap-4 sm:grid-cols-3">
            {mode === 'create' ? <label className="grid gap-2 text-sm">Status<select name="status" defaultValue={value(task, prefill, 'status', 'open')} className="rounded-lg border bg-transparent px-3 py-2">{statusOptions.map((option) => <option key={option.key} value={option.key}>{option.label}</option>)}</select></label> : null}
            <label className="grid gap-2 text-sm">Priority<select name="priority" defaultValue={value(task, prefill, 'priority', 'normal')} className="rounded-lg border bg-transparent px-3 py-2">{priorityOptions.map((option) => <option key={option.key} value={option.key}>{option.label}</option>)}</select></label>
            <label className="grid gap-2 text-sm">Due date<input type="datetime-local" name="due_at" defaultValue={value(task, prefill, 'due_at')} className="rounded-lg border bg-transparent px-3 py-2" /></label>
        </div>
        {select('assigned_user_id', 'Assignee', assigneeOptions, value(task, prefill, 'assigned_user_id'))}
        <div className="grid gap-4 sm:grid-cols-2">
            {select('customer_id', 'Customer', customerOptions, value(task, prefill, 'customer_id'))}
            {select('lead_id', 'Lead', leadOptions, value(task, prefill, 'lead_id'))}
            {select('deal_id', 'Deal', dealOptions, value(task, prefill, 'deal_id'))}
            {select('conversation_id', 'Conversation', conversationOptions, value(task, prefill, 'conversation_id'))}
            {select('support_ticket_id', 'Support ticket', ticketOptions, value(task, prefill, 'support_ticket_id'))}
            {select('appointment_id', 'Appointment', appointmentOptions, value(task, prefill, 'appointment_id'))}
        </div>
        <Button type="submit">{mode === 'create' ? 'Create task' : 'Save changes'}</Button>
    </form>;
}
