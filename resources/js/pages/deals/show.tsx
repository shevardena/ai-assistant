import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { show as customerShow } from '@/routes/customers';
import { index as dealsIndex, lost, reopen, won } from '@/routes/deals';
import { show as leadShow } from '@/routes/leads';
import { create as taskCreate, show as taskShow } from '@/routes/tasks';
import type { DealDetailPageProps } from '@/types';

export default function DealShow({
    deal,
    tasks,
    pipelineOptions,
}: DealDetailPageProps) {
    const { currentTeam } = usePage().props;

    if (!currentTeam) {
        return null;
    }

    const reopenStage = pipelineOptions
        .find((pipeline) => pipeline.id === deal.pipeline.id)
        ?.stages.find((stage) => stage.semanticType === 'open');

    return (
        <>
            <Head title={`${deal.title} · Deals`} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <Link
                    href={dealsIndex(currentTeam.slug).url}
                    className="flex w-fit items-center gap-2 text-sm text-muted-foreground"
                >
                    <ArrowLeft className="size-4" />
                    Back to deals
                </Link>
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
                    <Heading
                        variant="small"
                        title={deal.title}
                        description={
                            deal.description ?? 'Deal details and activity.'
                        }
                    />
                    <Badge
                        variant={
                            deal.status === 'lost'
                                ? 'destructive'
                                : deal.status === 'won'
                                  ? 'default'
                                  : 'secondary'
                        }
                    >
                        {deal.status}
                    </Badge>
                </div>
                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
                    <div className="grid gap-6">
                        <Card>
                            <CardHeader className="border-b">
                                <CardTitle className="text-base">
                                    Deal details
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-4 p-4 sm:grid-cols-2">
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Value
                                    </p>
                                    <p className="font-medium">
                                        {deal.valueAmount === null
                                            ? '—'
                                            : `${deal.currency} ${Number(deal.valueAmount).toLocaleString()}`}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Stage
                                    </p>
                                    <p className="font-medium">
                                        {deal.stage.name}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Pipeline
                                    </p>
                                    <p className="font-medium">
                                        {deal.pipeline.name}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Owner
                                    </p>
                                    <p className="font-medium">
                                        {deal.owner?.name ?? 'Unassigned'}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Expected close
                                    </p>
                                    <p className="font-medium">
                                        {deal.expectedCloseDate ?? '—'}
                                    </p>
                                </div>
                                {deal.customer ? (
                                    <div>
                                        <p className="text-sm text-muted-foreground">
                                            Customer
                                        </p>
                                        <Link
                                            className="font-medium hover:underline"
                                            href={
                                                customerShow([
                                                    currentTeam.slug,
                                                    deal.customer.id,
                                                ]).url
                                            }
                                        >
                                            {deal.customer.name}
                                        </Link>
                                    </div>
                                ) : null}
                                {deal.lead ? (
                                    <div>
                                        <p className="text-sm text-muted-foreground">
                                            Source lead
                                        </p>
                                        <Link
                                            className="font-medium hover:underline"
                                            href={
                                                leadShow([
                                                    currentTeam.slug,
                                                    deal.lead.reference,
                                                ]).url
                                            }
                                        >
                                            {deal.lead.name ??
                                                deal.lead.reference}
                                        </Link>
                                    </div>
                                ) : null}
                            </CardContent>
                        </Card>
                        <Card><CardHeader className="flex flex-row items-center justify-between border-b"><CardTitle className="text-base">Tasks & follow-ups</CardTitle><Link href={taskCreate(currentTeam.slug, { query: { deal_id: deal.id } }).url} className="text-sm text-primary hover:underline">Add task</Link></CardHeader><CardContent className="grid gap-3 p-4">{tasks.length ? tasks.map((task) => <Link key={task.id} href={taskShow([currentTeam.slug, task.id]).url} className="flex items-center justify-between rounded-lg border p-3 text-sm hover:border-primary"><span>{task.title}</span><Badge variant={task.overdue ? 'destructive' : 'outline'}>{task.status.replace('_', ' ')}</Badge></Link>) : <p className="text-sm text-muted-foreground">No tasks linked to this deal.</p>}</CardContent></Card>
                        <Card>
                            <CardHeader className="border-b">
                                <CardTitle className="text-base">
                                    Activity
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-4 p-4">
                                {deal.activities.length ? (
                                    deal.activities.map((activity, index) => (
                                        <div
                                            key={`${activity.timestamp}-${index}`}
                                            className="border-b pb-3 last:border-0"
                                        >
                                            <p className="font-medium">
                                                {activity.title}
                                            </p>
                                            <p className="text-sm text-muted-foreground">
                                                {activity.description ?? ''} ·{' '}
                                                {activity.actor ?? 'System'}
                                            </p>
                                        </div>
                                    ))
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        No activity yet.
                                    </p>
                                )}
                            </CardContent>
                        </Card>
                    </div>
                    <Card className="h-fit">
                        <CardHeader className="border-b">
                            <CardTitle className="text-base">Actions</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-3 p-4">
                            {deal.status === 'open' ? (
                                <>
                                    <Button
                                        onClick={() =>
                                            router.post(
                                                won([currentTeam.slug, deal.id])
                                                    .url,
                                            )
                                        }
                                    >
                                        Mark won
                                    </Button>
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            router.post(
                                                lost([
                                                    currentTeam.slug,
                                                    deal.id,
                                                ]).url,
                                                {
                                                    lost_reason:
                                                        window.prompt(
                                                            'Lost reason (optional)',
                                                        ) ?? '',
                                                },
                                            )
                                        }
                                    >
                                        Mark lost
                                    </Button>
                                </>
                            ) : (
                                <Button
                                    disabled={!reopenStage}
                                    onClick={() =>
                                        reopenStage
                                            ? router.post(
                                                  reopen([
                                                      currentTeam.slug,
                                                      deal.id,
                                                  ]).url,
                                                  { stage_id: reopenStage.id },
                                              )
                                            : undefined
                                    }
                                >
                                    Reopen
                                </Button>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}
