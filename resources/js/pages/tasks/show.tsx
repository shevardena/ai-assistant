import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import Heading from '@/components/heading';
import TaskForm from '@/components/task-form';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { index as tasksIndex, update as tasksUpdate, complete, reopen, cancel } from '@/routes/tasks';
import type { TaskDetailPageProps } from '@/types';

export default function TaskShow({ task, activity, ...formProps }: TaskDetailPageProps) {
    const { currentTeam } = usePage().props;

    if (!currentTeam) {
return null;
}

    const args = [currentTeam.slug, task.id] as [string, number];
    const action = (url: string) => router.post(url, {}, { preserveScroll: true });

    return <><Head title={`${task.title} · Tasks`} /><div className="flex flex-col gap-6 p-4 md:p-6"><Link href={tasksIndex(currentTeam.slug).url} className="flex w-fit items-center gap-2 text-sm text-muted-foreground"><ArrowLeft className="size-4" />Back to tasks</Link><div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-end"><Heading variant="small" title={task.title} description={task.description ?? 'Internal CRM follow-up'} /><div className="flex flex-wrap gap-2"><Badge>{task.status.replace('_', ' ')}</Badge><Badge variant="outline">{task.priority}</Badge>{task.overdue ? <Badge variant="destructive">Overdue</Badge> : null}</div></div><div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]"><div className="grid gap-6"><TaskForm {...formProps} task={task} mode="edit" action={tasksUpdate(args).url} /><Card><CardHeader className="border-b"><CardTitle className="text-base">Activity</CardTitle></CardHeader><CardContent className="grid gap-3 p-4">{activity.length ? activity.map((item, index) => <div key={`${item.timestamp}-${index}`} className="border-b pb-3 last:border-0"><p className="font-medium">{item.title}</p><p className="text-sm text-muted-foreground">{item.description ?? ''} · {item.actor ?? 'System'}</p></div>) : <p className="text-sm text-muted-foreground">No activity yet.</p>}</CardContent></Card></div><Card className="h-fit"><CardHeader className="border-b"><CardTitle className="text-base">Actions</CardTitle></CardHeader><CardContent className="grid gap-2 p-4"><Button onClick={() => action(complete(args).url)} disabled={task.status === 'completed' || task.status === 'cancelled'}>Complete task</Button><Button variant="outline" onClick={() => action(reopen(args).url)} disabled={task.status !== 'completed'}>Reopen task</Button><Button variant="destructive" onClick={() => action(cancel(args).url)} disabled={task.status === 'completed' || task.status === 'cancelled'}>Cancel task</Button></CardContent></Card></div></div></>;
}
