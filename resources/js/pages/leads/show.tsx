import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, ExternalLink } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { show as actionShow } from '@/routes/actions';
import { show as conversationShow } from '@/routes/conversations';
import { show as customerShow } from '@/routes/customers';
import { create as dealCreate, show as dealShow } from '@/routes/deals';
import { index as leadsIndex, update as leadsUpdate } from '@/routes/leads';
import { create as taskCreate, show as taskShow } from '@/routes/tasks';
import type { LeadDetailPageProps, LeadStatus } from '@/types';

function formatDate(value: string | null): string {
    return value
        ? new Intl.DateTimeFormat(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(new Date(value))
        : '—';
}

function statusVariant(
    status: LeadStatus,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    return status === 'won'
        ? 'default'
        : status === 'lost'
          ? 'destructive'
          : status === 'new'
            ? 'secondary'
            : 'outline';
}

export default function LeadShow({ lead, tasks, statusOptions }: LeadDetailPageProps) {
    const { currentTeam } = usePage().props;
    const [status, setStatus] = useState<LeadStatus>(lead.status);

    if (!currentTeam) {
        return null;
    }

    const saveStatus = () => {
        router.patch(
            leadsUpdate([currentTeam.slug, lead.reference]).url,
            { status },
            { preserveScroll: true },
        );
    };

    return (
        <>
            <Head title={`${lead.name} · Leads`} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <Link
                    href={leadsIndex(currentTeam.slug).url}
                    className="flex w-fit items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" /> Back to Leads
                </Link>
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
                    <Heading
                        variant="small"
                        title={lead.name}
                        description="Privacy-safe lead details captured by your Bot."
                    />
                    <Badge variant={statusVariant(lead.status)}>
                        {lead.statusLabel}
                    </Badge>
                </div>

                <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_20rem]">
                    <div className="grid gap-6">
                        <Card>
                            <CardHeader className="border-b">
                                <CardTitle className="text-base">
                                    Contact
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-4 p-4 md:grid-cols-2 md:p-6">
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Name
                                    </p>
                                    <p className="mt-1 font-medium">
                                        {lead.name}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Email
                                    </p>
                                    <p className="mt-1 font-medium">
                                        {lead.email ?? '—'}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Phone
                                    </p>
                                    <p className="mt-1 font-medium">
                                        {lead.phone ?? '—'}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Captured
                                    </p>
                                    <p className="mt-1 font-medium">
                                        {formatDate(lead.capturedAt)}
                                    </p>
                                </div>
                            </CardContent>
                        </Card>
                        {lead.customer ? (
                            <Card>
                                <CardHeader className="border-b"><CardTitle className="text-base">Customer</CardTitle></CardHeader>
                                <CardContent className="p-4"><Link className="hover:underline" href={customerShow([currentTeam.slug, lead.customer.id]).url}>{lead.customer.name}</Link></CardContent>
                            </Card>
                        ) : null}
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between border-b"><CardTitle className="text-base">Deals</CardTitle><Link href={dealCreate(currentTeam.slug).url} className="text-sm text-primary hover:underline">Create deal</Link></CardHeader>
                            <CardContent className="grid gap-2 p-4">{lead.deals.length ? lead.deals.map((deal) => <Link key={deal.id} href={dealShow([currentTeam.slug, deal.id]).url} className="flex justify-between rounded-lg border p-3 text-sm hover:border-primary"><span>{deal.title}</span><Badge variant="outline">{deal.status}</Badge></Link>) : <p className="text-sm text-muted-foreground">No deal has been linked to this lead.</p>}</CardContent>
                        </Card>
                        <Card><CardHeader className="flex flex-row items-center justify-between border-b"><CardTitle className="text-base">Tasks & follow-ups</CardTitle><Link href={taskCreate(currentTeam.slug, { query: { lead_id: lead.id } }).url} className="text-sm text-primary hover:underline">Add task</Link></CardHeader><CardContent className="grid gap-2 p-4">{tasks.length ? tasks.map((task) => <Link key={task.id} href={taskShow([currentTeam.slug, task.id]).url} className="flex justify-between rounded-lg border p-3 text-sm hover:border-primary"><span>{task.title}</span><Badge variant={task.overdue ? 'destructive' : 'outline'}>{task.status.replace('_', ' ')}</Badge></Link>) : <p className="text-sm text-muted-foreground">No tasks linked to this lead.</p>}</CardContent></Card>
                        <Card>
                            <CardHeader className="border-b">
                                <CardTitle className="text-base">
                                    Context
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-4 p-4 md:grid-cols-2 md:p-6">
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Bot
                                    </p>
                                    <p className="mt-1 font-medium">
                                        {lead.bot?.name ?? '—'}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Source
                                    </p>
                                    <p className="mt-1 font-medium">
                                        {lead.sourceLabel}
                                    </p>
                                </div>
                                {lead.interestSummary ? (
                                    <div className="md:col-span-2">
                                        <p className="text-sm text-muted-foreground">
                                            Interest
                                        </p>
                                        <p className="mt-1 whitespace-pre-wrap">
                                            {lead.interestSummary}
                                        </p>
                                    </div>
                                ) : null}
                                {lead.providerReference ? (
                                    <div>
                                        <p className="text-sm text-muted-foreground">
                                            Provider reference
                                        </p>
                                        <p className="mt-1 font-mono text-sm break-all">
                                            {lead.providerReference}
                                        </p>
                                    </div>
                                ) : null}
                            </CardContent>
                        </Card>
                    </div>
                    <div className="grid h-fit gap-6">
                        <Card>
                            <CardHeader className="border-b">
                                <CardTitle className="text-base">
                                    Status
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-3 p-4">
                                <select
                                    aria-label="Lead status"
                                    value={status}
                                    onChange={(event) =>
                                        setStatus(
                                            event.target.value as LeadStatus,
                                        )
                                    }
                                    className="rounded-lg border bg-transparent px-3 py-2 text-sm"
                                >
                                    {statusOptions.map((option) => (
                                        <option
                                            key={option.key}
                                            value={option.key}
                                        >
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                                <Button onClick={saveStatus}>
                                    Save status
                                </Button>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader className="border-b">
                                <CardTitle className="text-base">
                                    Related records
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-3 p-4 text-sm">
                                {lead.conversation ? (
                                    <Link
                                        className="flex items-center justify-between hover:underline"
                                        href={
                                            conversationShow([
                                                currentTeam.slug,
                                                lead.conversation.reference,
                                            ]).url
                                        }
                                    >
                                        Conversation{' '}
                                        <ExternalLink className="size-4" />
                                    </Link>
                                ) : null}
                                {lead.action ? (
                                    <Link
                                        className="flex items-center justify-between hover:underline"
                                        href={
                                            actionShow([
                                                currentTeam.slug,
                                                lead.action.reference,
                                            ]).url
                                        }
                                    >
                                        Action history{' '}
                                        <ExternalLink className="size-4" />
                                    </Link>
                                ) : null}
                                {!lead.conversation && !lead.action ? (
                                    <p className="text-muted-foreground">
                                        No related records.
                                    </p>
                                ) : null}
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>
        </>
    );
}
