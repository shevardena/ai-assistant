import { Head, Link, router, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { index as tasksIndex, create as tasksCreate, show as tasksShow, complete } from '@/routes/tasks';
import type { Paginated, TaskFilters, TaskListItem, TaskPageProps } from '@/types';

function date(value: string | null): string {
 return value ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : 'No due date'; 
}
function statusVariant(status: TaskListItem['status']): 'default' | 'secondary' | 'destructive' | 'outline' {
 return status === 'completed' ? 'default' : status === 'cancelled' ? 'destructive' : status === 'in_progress' ? 'secondary' : 'outline'; 
}
function pagination(page: Paginated<TaskListItem>) {
 return page.last_page <= 1 ? null : <nav className="flex flex-wrap justify-center gap-2">{page.links.map((link, index) => link.url ? <Button key={`${link.label}-${index}`} size="sm" variant={link.active ? 'default' : 'outline'} asChild><Link href={link.url}>{link.label.replace('&laquo;', 'Previous').replace('&raquo;', 'Next')}</Link></Button> : <Button key={`${link.label}-${index}`} size="sm" variant="outline" disabled>{link.label.replace('&laquo;', 'Previous').replace('&raquo;', 'Next')}</Button>)}</nav>; 
}

export default function TasksIndex({ filters, tasks, metrics, assigneeOptions, customerOptions, leadOptions, dealOptions }: TaskPageProps) {
    const { t } = useTranslation();
    const { currentTeam } = usePage().props;

    if (!currentTeam) {
return null;
}

    const visit = (changes: Partial<TaskFilters>) => {
        const next = { ...filters, ...changes };
        router.get(tasksIndex(currentTeam.slug, { query: { scope: next.scope, status: next.status, priority: next.priority, assigned_user_id: next.assignedUserId, customer_id: next.customerId, lead_id: next.leadId, deal_id: next.dealId, due_from: next.dueFrom, due_to: next.dueTo, search: next.search } }).url);
    };
    const scopes: Array<[TaskFilters['scope'], string]> = [['my', 'My tasks'], ['all', 'All tasks'], ['overdue', 'Overdue'], ['upcoming', 'Upcoming'], ['completed', 'Completed']];

    return <><Head title={t('navigation.tasks')} /><div className="flex flex-col gap-6 p-4 md:p-6">
        <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-end"><Heading variant="small" title={t('navigation.tasks')} description="Internal team follow-ups across your CRM." /><Button asChild><Link href={tasksCreate(currentTeam.slug).url}><Plus className="mr-2 size-4" />New task</Link></Button></div>
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">{Object.entries(metrics).map(([key, count]) => <Card key={key}><CardContent className="p-4"><p className="text-sm capitalize text-muted-foreground">{key}</p><p className="text-2xl font-semibold">{count}</p></CardContent></Card>)}</div>
        <div className="flex flex-wrap gap-2">{scopes.map(([scope, label]) => <Button key={scope} size="sm" variant={filters.scope === scope ? 'default' : 'outline'} onClick={() => visit({ scope })}>{label}</Button>)}</div>
        <Card><CardContent className="grid gap-3 p-4 md:grid-cols-4"><input defaultValue={filters.search ?? ''} placeholder="Search tasks" className="rounded-lg border bg-transparent px-3 py-2 text-sm" onKeyDown={(event) => {
 if (event.key === 'Enter') {
visit({ search: event.currentTarget.value || null });
} 
}} /><select value={filters.priority ?? ''} onChange={(event) => visit({ priority: event.target.value as TaskFilters['priority'] || null })} className="rounded-lg border bg-transparent px-3 py-2 text-sm"><option value="">All priorities</option>{['low', 'normal', 'high', 'urgent'].map((priority) => <option key={priority} value={priority}>{priority}</option>)}</select><select value={filters.assignedUserId ?? ''} onChange={(event) => visit({ assignedUserId: event.target.value ? Number(event.target.value) : null })} className="rounded-lg border bg-transparent px-3 py-2 text-sm"><option value="">All assignees</option>{assigneeOptions.map((user) => <option key={user.id} value={user.id}>{user.name}</option>)}</select><select value={filters.customerId ?? ''} onChange={(event) => visit({ customerId: event.target.value ? Number(event.target.value) : null })} className="rounded-lg border bg-transparent px-3 py-2 text-sm"><option value="">All customers</option>{customerOptions.map((customer) => <option key={customer.id} value={customer.id}>{customer.name}</option>)}</select><select value={filters.leadId ?? ''} onChange={(event) => visit({ leadId: event.target.value ? Number(event.target.value) : null })} className="rounded-lg border bg-transparent px-3 py-2 text-sm"><option value="">All leads</option>{leadOptions.map((lead) => <option key={lead.id} value={lead.id}>{lead.name}</option>)}</select><select value={filters.dealId ?? ''} onChange={(event) => visit({ dealId: event.target.value ? Number(event.target.value) : null })} className="rounded-lg border bg-transparent px-3 py-2 text-sm"><option value="">All deals</option>{dealOptions.map((deal) => <option key={deal.id} value={deal.id}>{deal.title}</option>)}</select></CardContent></Card>
        <div className="flex flex-wrap gap-3"><select value={filters.status ?? ''} onChange={(event) => visit({ status: event.target.value as TaskFilters['status'] || null })} className="rounded-lg border bg-transparent px-3 py-2 text-sm"><option value="">All statuses</option>{['open', 'in_progress', 'completed', 'cancelled'].map((status) => <option key={status} value={status}>{status.replace('_', ' ')}</option>)}</select><input type="date" value={filters.dueFrom ?? ''} onChange={(event) => visit({ dueFrom: event.target.value || null })} className="rounded-lg border bg-transparent px-3 py-2 text-sm" /><input type="date" value={filters.dueTo ?? ''} onChange={(event) => visit({ dueTo: event.target.value || null })} className="rounded-lg border bg-transparent px-3 py-2 text-sm" /></div>
        <div className="grid gap-3">{tasks.data.length ? tasks.data.map((task) => <Card key={task.id} className={task.overdue ? 'border-destructive/60' : ''}><CardContent className="flex flex-col gap-3 p-4 md:flex-row md:items-center md:justify-between"><div className="min-w-0"><div className="flex flex-wrap items-center gap-2"><Link href={tasksShow([currentTeam.slug, task.id]).url} className="font-medium hover:underline">{task.title}</Link><Badge variant={statusVariant(task.status)}>{task.status.replace('_', ' ')}</Badge><Badge variant={task.priority === 'urgent' ? 'destructive' : 'outline'}>{task.priority}</Badge>{task.overdue ? <Badge variant="destructive">Overdue</Badge> : null}</div><p className="mt-1 text-sm text-muted-foreground">{task.relatedTo?.label ?? 'No CRM record'} · {task.assignee?.name ?? 'Unassigned'} · {date(task.dueAt)}</p></div><div className="flex gap-2"><Button size="sm" variant="outline" onClick={() => router.post(complete([currentTeam.slug, task.id]).url, {}, { preserveScroll: true })} disabled={task.status === 'completed' || task.status === 'cancelled'}>Complete</Button></div></CardContent></Card>) : <Card><CardContent className="p-8 text-center text-sm text-muted-foreground">No tasks match this view.</CardContent></Card>}</div>
        {pagination(tasks)}
    </div></>;
}
