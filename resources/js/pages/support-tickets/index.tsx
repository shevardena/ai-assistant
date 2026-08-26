import { Head, Link, router, usePage } from '@inertiajs/react';
import { Search, Ticket } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    index as ticketsIndex,
    show as ticketsShow,
} from '@/routes/support-tickets';
import type {
    Paginated,
    SupportTicketFilters,
    SupportTicketPageProps,
    SupportTicketRange,
    SupportTicketStatus,
} from '@/types';

const ranges: Array<{ value: SupportTicketRange; label: string }> = [
    { value: 'today', label: 'Today' },
    { value: '7d', label: '7 days' },
    { value: '30d', label: '30 days' },
    { value: '90d', label: '90 days' },
    { value: 'all', label: 'All time' },
];
const statuses: Array<{ value: SupportTicketStatus | 'all'; label: string }> = [
    { value: 'all', label: 'All statuses' },
    { value: 'open', label: 'Open' },
    { value: 'in_progress', label: 'In progress' },
    { value: 'resolved', label: 'Resolved' },
    { value: 'closed', label: 'Closed' },
];
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
function pagination<T>(page: Paginated<T>) {
    return page.last_page <= 1 ? null : (
        <nav
            className="flex flex-wrap justify-center gap-2"
            aria-label="Support tickets pagination"
        >
            {page.links.map((link, index) =>
                link.url ? (
                    <Button
                        key={`${link.label}-${index}`}
                        variant={link.active ? 'default' : 'outline'}
                        size="sm"
                        asChild
                    >
                        <Link href={link.url}>
                            {link.label
                                .replace('&laquo;', 'Previous')
                                .replace('&raquo;', 'Next')}
                        </Link>
                    </Button>
                ) : (
                    <Button
                        key={`${link.label}-${index}`}
                        variant="outline"
                        size="sm"
                        disabled
                    >
                        {link.label
                            .replace('&laquo;', 'Previous')
                            .replace('&raquo;', 'Next')}
                    </Button>
                ),
            )}
        </nav>
    );
}

export default function SupportTicketsIndex({
    filters,
    botOptions,
    summary,
    tickets,
}: SupportTicketPageProps) {
    const { t } = useTranslation();
    const { currentTeam } = usePage().props;
    const [search, setSearch] = useState(filters.search ?? '');

    if (!currentTeam) {
        return null;
    }

    const visit = (changes: Partial<SupportTicketFilters>) => {
        const next = { ...filters, ...changes };
        router.get(ticketsIndex(currentTeam.slug, { query: next }).url);
    };
    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        visit({ search: search.trim() || null });
    };
    const cards = [
        ['Total', summary.total],
        ['Open', summary.open],
        ['In progress', summary.inProgress],
        ['Resolved', summary.resolved],
        ['Closed', summary.closed],
    ];

    return (
        <>
            <Head title={t('navigation.support_tickets')} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <Heading
                    variant="small"
                    title={t('navigation.support_tickets')}
                    description="Review customer support actions created by your Bots."
                />
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    {cards.map(([label, value]) => (
                        <Card key={label}>
                            <CardContent className="p-4">
                                <p className="text-sm text-muted-foreground">
                                    {label}
                                </p>
                                <p className="mt-2 text-3xl font-semibold tracking-tight">
                                    {value}
                                </p>
                            </CardContent>
                        </Card>
                    ))}
                </div>
                <div className="flex flex-col gap-3 rounded-xl border p-3">
                    <div className="flex flex-wrap gap-1 rounded-lg border p-1">
                        {ranges.map((range) => (
                            <Link
                                key={range.value}
                                href={
                                    ticketsIndex(currentTeam.slug, {
                                        query: {
                                            ...filters,
                                            range: range.value,
                                        },
                                    }).url
                                }
                                className={`rounded-md px-3 py-1.5 text-sm ${filters.range === range.value ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted hover:text-foreground'}`}
                            >
                                {range.label}
                            </Link>
                        ))}
                    </div>
                    <div className="flex flex-col gap-3 lg:flex-row">
                        <form
                            onSubmit={submit}
                            className="flex min-w-0 flex-1 gap-2"
                        >
                            <div className="flex min-w-0 flex-1 items-center gap-2 rounded-lg border px-3">
                                <Search className="size-4 text-muted-foreground" />
                                <input
                                    aria-label="Search support tickets"
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                    placeholder="Search subject, customer, or reference"
                                    className="min-w-0 flex-1 bg-transparent py-2 text-sm outline-none"
                                />
                            </div>
                            <Button type="submit" variant="outline">
                                Search
                            </Button>
                        </form>
                        <select
                            aria-label="Filter by Bot"
                            value={filters.bot ?? ''}
                            onChange={(event) =>
                                visit({ bot: event.target.value || null })
                            }
                            className="rounded-lg border bg-transparent px-3 py-2 text-sm"
                        >
                            <option value="">All Bots</option>
                            {botOptions.map((bot) => (
                                <option key={bot.slug} value={bot.slug}>
                                    {bot.name}
                                </option>
                            ))}
                        </select>
                        <select
                            aria-label="Filter by status"
                            value={filters.status}
                            onChange={(event) =>
                                visit({
                                    status: event.target.value as
                                        SupportTicketStatus | 'all',
                                })
                            }
                            className="rounded-lg border bg-transparent px-3 py-2 text-sm"
                        >
                            {statuses.map((status) => (
                                <option key={status.value} value={status.value}>
                                    {status.label}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>
                {tickets.data.length === 0 ? (
                    <div className="rounded-xl border border-dashed p-10 text-center">
                        <Ticket className="mx-auto size-8 text-muted-foreground" />
                        <p className="mt-3 font-medium">
                            No support tickets found
                        </p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Confirmed support actions will appear here.
                        </p>
                    </div>
                ) : (
                    <>
                        <div className="overflow-hidden rounded-xl border">
                            <div className="hidden grid-cols-[minmax(14rem,1.5fr)_minmax(10rem,1fr)_10rem_12rem] gap-4 border-b bg-muted/30 px-4 py-3 text-sm text-muted-foreground md:grid">
                                <span>Ticket</span>
                                <span>Bot</span>
                                <span>Status</span>
                                <span>Created</span>
                            </div>
                            <div className="divide-y">
                                {tickets.data.map((ticket) => (
                                    <Link
                                        key={ticket.reference}
                                        href={
                                            ticketsShow([
                                                currentTeam.slug,
                                                ticket.reference,
                                            ]).url
                                        }
                                        className="grid gap-2 px-4 py-4 hover:bg-muted/30 md:grid-cols-[minmax(14rem,1.5fr)_minmax(10rem,1fr)_10rem_12rem] md:items-center md:gap-4"
                                    >
                                        <div>
                                            <p className="font-medium">
                                                {ticket.subject}
                                            </p>
                                            <p className="text-sm text-muted-foreground">
                                                {ticket.customerName ??
                                                    ticket.customerEmail ??
                                                    'No customer details'}
                                            </p>
                                        </div>
                                        <span className="text-sm">
                                            {ticket.bot?.name ?? '—'}
                                        </span>
                                        <span>
                                            <Badge
                                                variant={variant(ticket.status)}
                                            >
                                                {ticket.statusLabel}
                                            </Badge>
                                        </span>
                                        <span className="text-sm">
                                            {date(ticket.createdAt)}
                                        </span>
                                    </Link>
                                ))}
                            </div>
                        </div>
                        {pagination(tickets)}
                    </>
                )}
            </div>
        </>
    );
}
