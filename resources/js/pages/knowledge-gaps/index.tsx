import { Head, Link, router, usePage } from '@inertiajs/react';
import { AlertCircle, Check, Lightbulb, Search, X } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { show as conversationShow } from '@/routes/conversations';
import {
    index as knowledgeGapsIndex,
    update as knowledgeGapsUpdate,
} from '@/routes/knowledge-gaps';
import type {
    KnowledgeGapFilters,
    KnowledgeGapGroup,
    KnowledgeGapPageProps,
    KnowledgeGapRange,
    KnowledgeGapReason,
    KnowledgeGapStatus,
    Paginated,
} from '@/types';

const ranges: Array<{ value: KnowledgeGapRange; label: string }> = [
    { value: 'today', label: 'Today' },
    { value: '7d', label: 'Last 7 days' },
    { value: '30d', label: 'Last 30 days' },
    { value: '90d', label: 'Last 90 days' },
];

const statuses: Array<{ value: KnowledgeGapStatus | 'all'; label: string }> = [
    { value: 'open', label: 'Open' },
    { value: 'resolved', label: 'Resolved' },
    { value: 'ignored', label: 'Ignored' },
    { value: 'all', label: 'All statuses' },
];

const reasons: Array<{ value: KnowledgeGapReason | ''; label: string }> = [
    { value: '', label: 'All reasons' },
    { value: 'no_knowledge_match', label: 'No knowledge match' },
    { value: 'no_results', label: 'No search results' },
];

function formatDate(value: string | null): string {
    return value
        ? new Intl.DateTimeFormat(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(new Date(value))
        : '—';
}

function number(value: number): string {
    return new Intl.NumberFormat().format(value);
}

function reasonLabel(reason: KnowledgeGapReason): string {
    return reason === 'no_knowledge_match'
        ? 'No knowledge match'
        : 'No search results';
}

function statusLabel(status: KnowledgeGapStatus): string {
    return status.charAt(0).toUpperCase() + status.slice(1);
}

function pagination<T>(page: Paginated<T>) {
    return page.last_page > 1 ? (
        <nav
            className="flex flex-wrap items-center justify-center gap-2"
            aria-label="Knowledge gaps pagination"
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
    ) : null;
}

export default function KnowledgeGaps({
    filters,
    botOptions,
    summary,
    gaps,
}: KnowledgeGapPageProps) {
    const { t } = useTranslation();
    const { currentTeam } = usePage().props;
    const [search, setSearch] = useState(filters.search ?? '');

    if (!currentTeam) {
        return null;
    }

    const team = currentTeam;

    const visit = (next: Partial<KnowledgeGapFilters>) => {
        const nextFilters = { ...filters, ...next };

        router.get(
            knowledgeGapsIndex(team.slug, {
                query: {
                    bot: nextFilters.bot,
                    range: nextFilters.range,
                    status: nextFilters.status,
                    reason: nextFilters.reason,
                    search: nextFilters.search,
                },
            }).url,
        );
    };

    function submitSearch(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        visit({ search: search.trim() || null });
    }

    function updateStatus(
        group: KnowledgeGapGroup,
        status: KnowledgeGapStatus,
    ) {
        router.patch(
            knowledgeGapsUpdate([team.slug, group.groupReference]).url,
            { status },
            { preserveScroll: true },
        );
    }

    const cards: Array<{ label: string; value: number }> = [
        { label: 'Open gaps', value: summary.openGaps },
        {
            label: 'Affected conversations',
            value: summary.affectedConversations,
        },
        { label: 'Repeated questions', value: summary.repeatedQuestions },
    ];

    return (
        <>
            <Head title={t('navigation.knowledge_gaps')} />

            <div className="flex flex-col gap-6 p-4 md:p-6">
                <Heading
                    variant="small"
                    title={t('navigation.knowledge_gaps')}
                    description="Find customer questions your Bots could not answer reliably."
                />

                <div className="grid gap-4 sm:grid-cols-3">
                    {cards.map((card) => (
                        <div key={card.label} className="rounded-xl border p-4">
                            <p className="text-sm text-muted-foreground">
                                {card.label}
                            </p>
                            <p className="mt-2 text-3xl font-semibold tracking-tight">
                                {number(card.value)}
                            </p>
                        </div>
                    ))}
                </div>

                <div className="flex flex-col gap-3 rounded-xl border p-3 lg:flex-row lg:items-center">
                    <div className="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm">
                        <label htmlFor="knowledge-gap-bot" className="sr-only">
                            Filter by Bot
                        </label>
                        <select
                            id="knowledge-gap-bot"
                            value={filters.bot ?? ''}
                            onChange={(event) =>
                                visit({ bot: event.target.value || null })
                            }
                            className="bg-transparent outline-none"
                        >
                            <option value="">All Bots</option>
                            {botOptions.map((bot) => (
                                <option key={bot.slug} value={bot.slug}>
                                    {bot.name}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="flex flex-wrap gap-1 rounded-lg border p-1">
                        {ranges.map((range) => (
                            <Link
                                key={range.value}
                                href={
                                    knowledgeGapsIndex(team.slug, {
                                        query: {
                                            bot: filters.bot,
                                            range: range.value,
                                            status: filters.status,
                                            reason: filters.reason,
                                            search: filters.search,
                                        },
                                    }).url
                                }
                                className={`rounded-md px-3 py-1.5 text-sm transition-colors ${
                                    filters.range === range.value
                                        ? 'bg-primary text-primary-foreground'
                                        : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                                }`}
                            >
                                {range.label}
                            </Link>
                        ))}
                    </div>

                    <div className="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm">
                        <label
                            htmlFor="knowledge-gap-status"
                            className="sr-only"
                        >
                            Filter by status
                        </label>
                        <select
                            id="knowledge-gap-status"
                            value={filters.status}
                            onChange={(event) =>
                                visit({
                                    status: event.target
                                        .value as KnowledgeGapFilters['status'],
                                })
                            }
                            className="bg-transparent outline-none"
                        >
                            {statuses.map((status) => (
                                <option key={status.value} value={status.value}>
                                    {status.label}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm">
                        <label
                            htmlFor="knowledge-gap-reason"
                            className="sr-only"
                        >
                            Filter by reason
                        </label>
                        <select
                            id="knowledge-gap-reason"
                            value={filters.reason ?? ''}
                            onChange={(event) =>
                                visit({
                                    reason: (event.target.value ||
                                        null) as KnowledgeGapReason | null,
                                })
                            }
                            className="bg-transparent outline-none"
                        >
                            {reasons.map((reason) => (
                                <option key={reason.value} value={reason.value}>
                                    {reason.label}
                                </option>
                            ))}
                        </select>
                    </div>

                    <form
                        className="flex min-w-0 flex-1 items-center gap-2 rounded-lg border px-3 py-2"
                        onSubmit={submitSearch}
                    >
                        <Search className="size-4 shrink-0 text-muted-foreground" />
                        <label
                            htmlFor="knowledge-gap-search"
                            className="sr-only"
                        >
                            Search knowledge gaps
                        </label>
                        <input
                            id="knowledge-gap-search"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Search unanswered questions"
                            className="min-w-0 flex-1 bg-transparent text-sm outline-none"
                        />
                        <Button type="submit" size="sm" variant="ghost">
                            Search
                        </Button>
                    </form>
                </div>

                {gaps.data.length > 0 ? (
                    <div className="grid gap-3">
                        {gaps.data.map((gap) => (
                            <article
                                key={gap.groupReference}
                                className="grid gap-4 rounded-xl border p-4"
                            >
                                <div className="flex flex-col justify-between gap-3 lg:flex-row lg:items-start">
                                    <div className="min-w-0">
                                        <h2 className="font-medium">
                                            {gap.question}
                                        </h2>
                                        <div className="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-sm text-muted-foreground">
                                            <span>{gap.bot.name}</span>
                                            <span>
                                                {reasonLabel(gap.reason)}
                                            </span>
                                            <span>
                                                {formatDate(gap.lastAskedAt)}
                                            </span>
                                        </div>
                                    </div>
                                    <div className="flex shrink-0 items-center gap-2 text-sm">
                                        <span className="rounded-full bg-muted px-2 py-1">
                                            {number(gap.occurrenceCount)}{' '}
                                            {gap.occurrenceCount === 1
                                                ? 'occurrence'
                                                : 'occurrences'}
                                        </span>
                                        <span className="rounded-full border px-2 py-1">
                                            {statusLabel(gap.status)}
                                        </span>
                                    </div>
                                </div>

                                <div className="grid gap-2 border-t pt-3">
                                    {gap.occurrences.map((occurrence) => (
                                        <div
                                            key={`${occurrence.conversationReference}-${occurrence.askedAt}`}
                                            className="flex flex-col justify-between gap-2 text-sm sm:flex-row sm:items-center"
                                        >
                                            <span className="text-muted-foreground">
                                                {formatDate(occurrence.askedAt)}
                                            </span>
                                            <Link
                                                href={
                                                    conversationShow([
                                                        team.slug,
                                                        occurrence.conversationReference,
                                                    ]).url
                                                }
                                                className="text-primary hover:underline"
                                            >
                                                View conversation
                                            </Link>
                                        </div>
                                    ))}
                                    {gap.occurrencesCapped ? (
                                        <p className="text-xs text-muted-foreground">
                                            Showing the 10 most recent
                                            occurrences.
                                        </p>
                                    ) : null}
                                </div>

                                <div className="flex flex-wrap gap-2 border-t pt-3">
                                    {gap.status !== 'resolved' ? (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                updateStatus(gap, 'resolved')
                                            }
                                        >
                                            <Check /> Mark resolved
                                        </Button>
                                    ) : null}
                                    {gap.status !== 'ignored' ? (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            onClick={() =>
                                                updateStatus(gap, 'ignored')
                                            }
                                        >
                                            <X /> Ignore
                                        </Button>
                                    ) : null}
                                    {gap.status !== 'open' ? (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            onClick={() =>
                                                updateStatus(gap, 'open')
                                            }
                                        >
                                            <AlertCircle /> Reopen
                                        </Button>
                                    ) : null}
                                </div>
                            </article>
                        ))}
                    </div>
                ) : (
                    <div className="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed px-6 py-16 text-center">
                        <div className="flex size-12 items-center justify-center rounded-full bg-primary/10 text-primary">
                            <Lightbulb className="size-6" />
                        </div>
                        <div className="space-y-1">
                            <h2 className="font-medium">
                                {filters.search || filters.status !== 'open'
                                    ? 'No gaps match these filters.'
                                    : 'No knowledge gaps found.'}
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                Questions the assistant cannot answer reliably
                                will appear here.
                            </p>
                        </div>
                    </div>
                )}

                {pagination(gaps)}
            </div>
        </>
    );
}
