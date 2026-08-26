import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, GitBranch, Pencil } from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { edit, index, activate, disable } from '@/routes/workflows';
import type { Workflow, WorkflowMetadata, WorkflowRun } from '@/types';

type Props = {
    workflow: Workflow;
    metadata: WorkflowMetadata;
    runs: WorkflowRun[];
};
function label(value: string): string {
    return value
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());
}
function formatDate(value: string | null): string {
    return value
        ? new Intl.DateTimeFormat(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(new Date(value))
        : '—';
}

export default function WorkflowsShow({ workflow, runs }: Props) {
    const { currentTeam, currentTeamPermissions } = usePage().props;

    if (!currentTeam) {
        return null;
    }

    const canManage = currentTeamPermissions?.['workflows.manage'] === true;

    return (
        <>
            <Head title={workflow.name} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                    <div className="flex items-start gap-3">
                        <Button variant="ghost" size="icon" asChild>
                            <Link
                                href={index(currentTeam.slug).url}
                                aria-label="Back to workflows"
                            >
                                <ArrowLeft />
                            </Link>
                        </Button>
                        <div className="flex items-start gap-3">
                            <div className="mt-1 flex size-10 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                <GitBranch className="size-5" />
                            </div>
                            <Heading
                                variant="small"
                                title={workflow.name}
                                description={
                                    workflow.description ??
                                    label(workflow.triggerType)
                                }
                            />
                        </div>
                    </div>
                    {canManage ? (
                        <div className="flex items-center gap-2">
                            <Button variant="outline" asChild>
                                <Link
                                    href={
                                        edit([
                                            currentTeam.slug,
                                            workflow.publicId,
                                        ]).url
                                    }
                                >
                                    <Pencil />
                                    Edit
                                </Link>
                            </Button>
                            {workflow.status === 'active' ? (
                                <Link
                                    href={
                                        disable([
                                            currentTeam.slug,
                                            workflow.publicId,
                                        ]).url
                                    }
                                    method="patch"
                                    as="button"
                                    className="inline-flex h-9 items-center rounded-md border px-3 text-sm font-medium"
                                >
                                    Disable
                                </Link>
                            ) : (
                                <Link
                                    href={
                                        activate([
                                            currentTeam.slug,
                                            workflow.publicId,
                                        ]).url
                                    }
                                    method="patch"
                                    as="button"
                                    className="inline-flex h-9 items-center rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground"
                                >
                                    Activate
                                </Link>
                            )}
                        </div>
                    ) : null}
                </div>
                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <CardTitle>Definition</CardTitle>
                                <Badge
                                    variant={
                                        workflow.status === 'active'
                                            ? 'default'
                                            : 'secondary'
                                    }
                                >
                                    {label(workflow.status)}
                                </Badge>
                            </div>
                        </CardHeader>
                        <CardContent className="grid gap-5">
                            <div>
                                <p className="text-sm text-muted-foreground">
                                    When
                                </p>
                                <p className="font-medium">
                                    {label(workflow.triggerType)}
                                </p>
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">
                                    If all of these conditions match
                                </p>
                                {workflow.conditions.length ? (
                                    <ul className="mt-2 grid gap-2">
                                        {workflow.conditions.map(
                                            (condition, index) => (
                                                <li
                                                    key={index}
                                                    className="rounded-md border px-3 py-2 text-sm"
                                                >
                                                    {label(condition.type)}{' '}
                                                    {label(condition.operator)}{' '}
                                                    <span className="font-medium">
                                                        {String(
                                                            condition.value,
                                                        )}
                                                    </span>
                                                </li>
                                            ),
                                        )}
                                    </ul>
                                ) : (
                                    <p className="mt-2 text-sm">
                                        No conditions
                                    </p>
                                )}
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">
                                    Then, in order
                                </p>
                                <ol className="mt-2 grid gap-2">
                                    {workflow.actions.map((action, index) => (
                                        <li
                                            key={index}
                                            className="rounded-md border px-3 py-2 text-sm"
                                        >
                                            <span className="mr-2 text-muted-foreground">
                                                {index + 1}.
                                            </span>
                                            {label(action.type)}
                                        </li>
                                    ))}
                                </ol>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Recent runs</CardTitle>
                            <p className="text-sm text-muted-foreground">
                                Latest 20 runs. Raw event payloads are never
                                stored here.
                            </p>
                        </CardHeader>
                        <CardContent>
                            {runs.length ? (
                                <div className="divide-y rounded-lg border">
                                    {runs.map((run) => (
                                        <div
                                            key={run.publicId}
                                            className="flex flex-col gap-2 p-3 text-sm sm:flex-row sm:items-center sm:justify-between"
                                        >
                                            <div>
                                                <p className="font-medium">
                                                    {label(run.status)}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {formatDate(run.startedAt)}{' '}
                                                    · {run.triggerReference}
                                                </p>
                                            </div>
                                            <Badge
                                                variant={
                                                    run.status === 'completed'
                                                        ? 'default'
                                                        : run.status ===
                                                            'failed'
                                                          ? 'destructive'
                                                          : 'secondary'
                                                }
                                            >
                                                {run.errorCode
                                                    ? label(run.errorCode)
                                                    : label(run.status)}
                                            </Badge>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    No workflow runs yet.
                                </p>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}
