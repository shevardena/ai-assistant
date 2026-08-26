import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, Circle, Clock3 } from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { index as actionsIndex } from '@/routes/actions';
import { show as botShow } from '@/routes/bots';
import { show as conversationShow } from '@/routes/conversations';
import type {
    ActionHistoryDetailPageProps,
    ActionHistoryStatus,
} from '@/types';

function formatDate(value: string | null): string {
    return value
        ? new Intl.DateTimeFormat(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(new Date(value))
        : '—';
}

function statusVariant(
    status: ActionHistoryStatus,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (status === 'completed') {
        return 'default';
    }

    if (status === 'failed') {
        return 'destructive';
    }

    if (status === 'cancelled') {
        return 'outline';
    }

    return 'secondary';
}

function sourceLabel(source: 'widget' | 'preview' | 'conversation'): string {
    return source === 'widget'
        ? 'Customer widget'
        : source === 'preview'
          ? 'Dashboard preview'
          : 'Conversation';
}

export default function ActionShow({ action }: ActionHistoryDetailPageProps) {
    const { currentTeam } = usePage().props;

    if (!currentTeam) {
        return null;
    }

    return (
        <>
            <Head title={`${action.label} · Action History`} />

            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-4">
                    <Link
                        href={actionsIndex(currentTeam.slug).url}
                        className="flex w-fit items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" />
                        Back to Action History
                    </Link>
                    <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
                        <Heading
                            variant="small"
                            title={action.label}
                            description="Safe audit details for this action."
                        />
                        <Badge variant={statusVariant(action.status)}>
                            {action.statusLabel}
                        </Badge>
                    </div>
                </div>

                <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_20rem]">
                    <div className="grid gap-6">
                        <Card>
                            <CardHeader className="border-b">
                                <CardTitle className="text-base">
                                    Overview
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-4 p-4 md:grid-cols-2 md:p-6">
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Bot
                                    </p>
                                    <p className="mt-1 font-medium">
                                        <Link
                                            href={
                                                botShow([
                                                    currentTeam.slug,
                                                    action.bot.id,
                                                ]).url
                                            }
                                            className="hover:underline"
                                        >
                                            {action.bot.name}
                                        </Link>
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Created
                                    </p>
                                    <p className="mt-1 font-medium">
                                        {formatDate(action.createdAt)}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Completed
                                    </p>
                                    <p className="mt-1 font-medium">
                                        {formatDate(action.completedAt)}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Duration
                                    </p>
                                    <p className="mt-1 font-medium">
                                        {action.durationMs === null
                                            ? '—'
                                            : `${action.durationMs} ms`}
                                    </p>
                                </div>
                                <div className="md:col-span-2">
                                    <p className="text-sm text-muted-foreground">
                                        Action reference
                                    </p>
                                    <p className="mt-1 font-mono text-sm break-all">
                                        {action.actionReference}
                                    </p>
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="border-b">
                                <CardTitle className="text-base">
                                    Lifecycle
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-4 p-4 md:p-6">
                                {action.lifecycle.map((step, index) => (
                                    <div
                                        key={`${step.key}-${index}`}
                                        className="flex items-start gap-3"
                                    >
                                        {index ===
                                        action.lifecycle.length - 1 ? (
                                            <CheckCircle2 className="mt-0.5 size-5 text-primary" />
                                        ) : (
                                            <Circle className="mt-0.5 size-5 text-muted-foreground" />
                                        )}
                                        <div>
                                            <p className="font-medium">
                                                {step.label}
                                            </p>
                                            <p className="text-sm text-muted-foreground">
                                                {formatDate(step.at)}
                                            </p>
                                        </div>
                                    </div>
                                ))}
                                {action.lifecycle.length === 1 ? (
                                    <p className="flex items-center gap-2 text-sm text-muted-foreground">
                                        <Clock3 className="size-4" />
                                        Waiting for the next lifecycle event.
                                    </p>
                                ) : null}
                            </CardContent>
                        </Card>

                        {action.status === 'pending_confirmation' ||
                        action.result.summary ||
                        action.errorSummary ? (
                            <Card>
                                <CardHeader className="border-b">
                                    <CardTitle className="text-base">
                                        {action.status === 'failed'
                                            ? 'Outcome'
                                            : 'Result'}
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="p-4 md:p-6">
                                    <p
                                        className={
                                            action.status === 'failed'
                                                ? 'text-sm text-destructive'
                                                : 'text-sm'
                                        }
                                    >
                                        {action.status ===
                                        'pending_confirmation'
                                            ? 'Waiting for customer confirmation.'
                                            : (action.errorSummary ??
                                              action.result.summary)}
                                    </p>
                                </CardContent>
                            </Card>
                        ) : null}
                    </div>

                    <aside className="grid content-start gap-4">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Conversation
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-3 text-sm">
                                {action.conversation ? (
                                    <>
                                        <div>
                                            <p className="text-muted-foreground">
                                                Source
                                            </p>
                                            <p className="mt-1 font-medium">
                                                {sourceLabel(
                                                    action.conversation.source,
                                                )}
                                            </p>
                                        </div>
                                        <Link
                                            href={
                                                conversationShow([
                                                    currentTeam.slug,
                                                    action.conversation
                                                        .reference,
                                                ]).url
                                            }
                                            className="text-primary hover:underline"
                                        >
                                            View conversation
                                        </Link>
                                    </>
                                ) : (
                                    <p className="text-muted-foreground">
                                        No conversation linked.
                                    </p>
                                )}
                            </CardContent>
                        </Card>
                    </aside>
                </div>
            </div>
        </>
    );
}
