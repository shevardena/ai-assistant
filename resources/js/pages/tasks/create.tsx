import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import Heading from '@/components/heading';
import TaskForm from '@/components/task-form';
import { index as tasksIndex, store as tasksStore } from '@/routes/tasks';
import type { TaskFormProps } from '@/types';

export default function TaskCreate(props: TaskFormProps) {
    const { currentTeam } = usePage().props;

    if (!currentTeam) {
return null;
}

    return <><Head title="New task" /><div className="flex flex-col gap-6 p-4 md:p-6"><Link href={tasksIndex(currentTeam.slug).url} className="flex w-fit items-center gap-2 text-sm text-muted-foreground"><ArrowLeft className="size-4" />Back to tasks</Link><Heading variant="small" title="New task" description="Create an internal follow-up for your team." /><TaskForm {...props} mode="create" action={tasksStore(currentTeam.slug).url} /></div></>;
}
