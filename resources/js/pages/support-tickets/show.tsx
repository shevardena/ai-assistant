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
import {
    index as ticketsIndex,
    update as ticketsUpdate,
} from '@/routes/support-tickets';
import type {
    SupportTicketDetailPageProps,
    SupportTicketStatus,
} from '@/types';

function date(value: string | null): string {
    return value
        ? new Intl.DateTimeFormat(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(new Date(value))
        : '—';
}
function variant(
    status: SupportTicketStatus,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    return status === 'resolved'
        ? 'default'
        : status === 'open'
          ? 'secondary'
          : 'outline';
}

export default function SupportTicketShow({
    ticket,
    statusOptions,
}: SupportTicketDetailPageProps) {
    const { currentTeam } = usePage().props;
    const [status, setStatus] = useState<SupportTicketStatus>(ticket.status);

    if (!currentTeam) {
        return null;
    }

    const save = () =>
        router.patch(
            ticketsUpdate([currentTeam.slug, ticket.reference]).url,
            { status },
            { preserveScroll: true },
        );

    return (
        <>
            <Head title={`${ticket.subject} · Support tickets`} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <Link
                    href={ticketsIndex(currentTeam.slug).url}
                    className="flex w-fit items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" /> Back to Support tickets
                </Link>
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
                    <Heading
                        variant="small"
                        title={ticket.subject}
                        description="Operational support details from a confirmed customer action."
                    />
                    <Badge variant={variant(ticket.status)}>
                        {ticket.statusLabel}
                    </Badge>
                </div>
                <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_20rem]">
                    <div className="grid gap-6">
                        <Card>
                            <CardHeader className="border-b">
                                <CardTitle className="text-base">
                                    Request
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-4 p-4 md:grid-cols-2 md:p-6">
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Customer
                                    </p>
                                    <p className="mt-1 font-medium">
                                        {ticket.customerName ?? '—'}
                                    </p>
                                </div>
                                {ticket.customer ? <div className="md:col-span-2"><p className="text-sm text-muted-foreground">Customer profile</p><Link className="mt-1 block font-medium hover:underline" href={customerShow([currentTeam.slug, ticket.customer.id]).url}>{ticket.customer.name}</Link></div> : null}
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Email
                                    </p>
                                    <p className="mt-1 font-medium">
                                        {ticket.customerEmail ?? '—'}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Created
                                    </p>
                                    <p className="mt-1 font-medium">
                                        {date(ticket.createdAt)}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Provider reference
                                    </p>
                                    <p className="mt-1 font-mono text-sm break-all">
                                        {ticket.providerReference ?? '—'}
                                    </p>
                                </div>
                                {ticket.summary ? (
                                    <div className="md:col-span-2">
                                        <p className="text-sm text-muted-foreground">
                                            Summary
                                        </p>
                                        <p className="mt-1 whitespace-pre-wrap">
                                            {ticket.summary}
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
                                    Internal status
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-3 p-4">
                                <select
                                    aria-label="Support ticket status"
                                    value={status}
                                    onChange={(event) =>
                                        setStatus(
                                            event.target
                                                .value as SupportTicketStatus,
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
                                <Button onClick={save}>Save status</Button>
                            </CardContent>
                        </Card>
                        {ticket.externalUrl ? (
                            <Card>
                                <CardHeader className="border-b">
                                    <CardTitle className="text-base">
                                        Provider
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="p-4">
                                    <a
                                        href={ticket.externalUrl}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="flex items-center justify-between text-sm hover:underline"
                                    >
                                        Open provider ticket{' '}
                                        <ExternalLink className="size-4" />
                                    </a>
                                </CardContent>
                            </Card>
                        ) : null}
                        <Card>
                            <CardHeader className="border-b">
                                <CardTitle className="text-base">
                                    Related records
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-3 p-4 text-sm">
                                {ticket.conversation ? (
                                    <Link
                                        className="flex items-center justify-between hover:underline"
                                        href={
                                            conversationShow([
                                                currentTeam.slug,
                                                ticket.conversation.reference,
                                            ]).url
                                        }
                                    >
                                        Conversation{' '}
                                        <ExternalLink className="size-4" />
                                    </Link>
                                ) : null}
                                {ticket.action ? (
                                    <Link
                                        className="flex items-center justify-between hover:underline"
                                        href={
                                            actionShow([
                                                currentTeam.slug,
                                                ticket.action.reference,
                                            ]).url
                                        }
                                    >
                                        Action history{' '}
                                        <ExternalLink className="size-4" />
                                    </Link>
                                ) : null}
                                {!ticket.conversation && !ticket.action ? (
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
