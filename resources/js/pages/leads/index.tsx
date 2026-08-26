import { Head, Link, router, usePage } from '@inertiajs/react';
import { Search, Users } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { index as leadsIndex, show as leadsShow } from '@/routes/leads';
import type {
    LeadFilters,
    LeadPageProps,
    LeadRange,
    LeadStatus,
    Paginated,
} from '@/types';

const ranges: Array<{ value: LeadRange; label: string }> = [
    { value: 'today', label: 'Today' },
    { value: '7d', label: '7 days' },
    { value: '30d', label: '30 days' },
    { value: '90d', label: '90 days' },
    { value: 'all', label: 'All time' },
];

const statuses: Array<{ value: LeadStatus | 'all'; label: string }> = [
    { value: 'all', label: 'All statuses' },
    { value: 'new', label: 'New' },
    { value: 'contacted', label: 'Contacted' },
    { value: 'qualified', label: 'Qualified' },
    { value: 'won', label: 'Won' },
    { value: 'lost', label: 'Lost' },
];

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

function number(value: number): string {
    return new Intl.NumberFormat().format(value);
}

function pagination<T>(page: Paginated<T>) {
    if (page.last_page <= 1) {
        return null;
    }

    return (
        <nav
            className="flex flex-wrap justify-center gap-2"
            aria-label="Leads pagination"
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

export default function LeadsIndex({
    filters,
    botOptions,
    summary,
    leads,
}: LeadPageProps) {
    const { t } = useTranslation();
    const { currentTeam } = usePage().props;
    const [search, setSearch] = useState(filters.search ?? '');

    if (!currentTeam) {
        return null;
    }

    const visit = (changes: Partial<LeadFilters>) => {
        const next = { ...filters, ...changes };

        router.get(
            leadsIndex(currentTeam.slug, {
                query: {
                    bot: next.bot,
                    range: next.range,
                    status: next.status,
                    search: next.search,
                },
            }).url,
        );
    };

    const submitSearch = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        visit({ search: search.trim() || null });
    };

    const summaryCards = [
        ['Total', summary.total],
        ['New', summary.new],
        ['Qualified', summary.qualified],
        ['Won', summary.won],
        ['Lost', summary.lost],
    ];

    return (
        <>
            <Head title={t('navigation.leads')} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <Heading
                    variant="small"
                    title={t('navigation.leads')}
                    description="Review customer contacts captured by your Bots."
                />

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    {summaryCards.map(([label, value]) => (
                        <Card key={label}>
                            <CardContent className="p-4">
                                <p className="text-sm text-muted-foreground">
                                    {label}
                                </p>
                                <p className="mt-2 text-3xl font-semibold tracking-tight">
                                    {number(value as number)}
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
                                    leadsIndex(currentTeam.slug, {
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
                            onSubmit={submitSearch}
                            className="flex min-w-0 flex-1 gap-2"
                        >
                            <div className="flex min-w-0 flex-1 items-center gap-2 rounded-lg border px-3">
                                <Search className="size-4 text-muted-foreground" />
                                <input
                                    aria-label="Search leads"
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                    placeholder="Search name, email, phone, or reference"
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
                                        LeadStatus | 'all',
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

                {leads.data.length === 0 ? (
                    <div className="rounded-xl border border-dashed p-10 text-center">
                        <Users className="mx-auto size-8 text-muted-foreground" />
                        <p className="mt-3 font-medium">No leads found</p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Captured leads will appear here after a confirmed
                            customer action.
                        </p>
                    </div>
                ) : (
                    <div className="overflow-hidden rounded-xl border">
                        <div className="hidden grid-cols-[minmax(12rem,1.5fr)_minmax(10rem,1fr)_minmax(8rem,1fr)_8rem] gap-4 border-b bg-muted/30 px-4 py-3 text-sm text-muted-foreground md:grid">
                            <span>Contact</span>
                            <span>Bot</span>
                            <span>Status</span>
                            <span>Captured</span>
                        </div>
                        <div className="divide-y">
                            {leads.data.map((lead) => (
                                <Link
                                    key={lead.reference}
                                    href={
                                        leadsShow([
                                            currentTeam.slug,
                                            lead.reference,
                                        ]).url
                                    }
                                    className="grid gap-3 px-4 py-4 transition-colors hover:bg-muted/30 md:grid-cols-[minmax(12rem,1.5fr)_minmax(10rem,1fr)_minmax(8rem,1fr)_8rem] md:items-center md:gap-4"
                                >
                                    <div className="min-w-0">
                                        <p className="truncate font-medium">
                                            {lead.name}
                                        </p>
                                        <p className="truncate text-sm text-muted-foreground">
                                            {lead.email ??
                                                lead.phone ??
                                                'No contact details'}
                                        </p>
                                    </div>
                                    <span className="text-sm">
                                        {lead.bot?.name ?? '—'}
                                    </span>
                                    <span>
                                        <Badge
                                            variant={statusVariant(lead.status)}
                                        >
                                            {lead.statusLabel}
                                        </Badge>
                                    </span>
                                    <span className="text-sm text-muted-foreground">
                                        {formatDate(lead.capturedAt)}
                                    </span>
                                </Link>
                            ))}
                        </div>
                    </div>
                )}

                {pagination(leads)}
            </div>
        </>
    );
}
