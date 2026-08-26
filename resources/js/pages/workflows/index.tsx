import { Head, Link, usePage } from '@inertiajs/react';
import { GitBranch, Plus } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { create, show } from '@/routes/workflows';
import type { Workflow } from '@/types';

function label(value: string): string {
    return value
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());
}

export default function WorkflowsIndex({
    workflows,
}: {
    workflows: Workflow[];
}) {
    const { t } = useTranslation();
    const { currentTeam } = usePage().props;

    if (!currentTeam) {
        return null;
    }

    return (
        <>
            <Head title={t('navigation.workflows')} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                    <Heading
                        variant="small"
                        title={t('navigation.workflows')}
                        description="Define deterministic automations for trusted Team events."
                    />
                    <Button asChild>
                        <Link href={create(currentTeam.slug).url}>
                            <Plus />
                            Create workflow
                        </Link>
                    </Button>
                </div>
                {workflows.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center gap-3 px-6 py-16 text-center">
                            <GitBranch className="size-9 text-muted-foreground" />
                            <h2 className="font-medium">No workflows yet</h2>
                            <p className="max-w-md text-sm text-muted-foreground">
                                Start with a lead, appointment, support ticket,
                                or human handoff event.
                            </p>
                            <Button asChild>
                                <Link href={create(currentTeam.slug).url}>
                                    Create your first workflow
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="grid gap-4">
                        {workflows.map((workflow) => (
                            <Link
                                key={workflow.publicId}
                                href={
                                    show([currentTeam.slug, workflow.publicId])
                                        .url
                                }
                            >
                                <Card className="transition-colors hover:bg-muted/30">
                                    <CardContent className="flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between">
                                        <div className="flex items-start gap-3">
                                            <div className="mt-0.5 flex size-9 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                                <GitBranch className="size-4" />
                                            </div>
                                            <div>
                                                <h2 className="font-medium">
                                                    {workflow.name}
                                                </h2>
                                                <p className="text-sm text-muted-foreground">
                                                    {label(
                                                        workflow.triggerType,
                                                    )}{' '}
                                                    · {workflow.actionCount}{' '}
                                                    {workflow.actionCount === 1
                                                        ? 'action'
                                                        : 'actions'}
                                                </p>
                                                {workflow.description ? (
                                                    <p className="mt-1 text-sm text-muted-foreground">
                                                        {workflow.description}
                                                    </p>
                                                ) : null}
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-3">
                                            <Badge
                                                variant={
                                                    workflow.status === 'active'
                                                        ? 'default'
                                                        : 'secondary'
                                                }
                                            >
                                                {label(workflow.status)}
                                            </Badge>
                                            <span className="text-xs text-muted-foreground">
                                                {workflow.lastRun
                                                    ? `Last run: ${label(workflow.lastRun.status)}`
                                                    : 'No runs yet'}
                                            </span>
                                        </div>
                                    </CardContent>
                                </Card>
                            </Link>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}
