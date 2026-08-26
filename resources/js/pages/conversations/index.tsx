import { Head, Link, router, usePage } from '@inertiajs/react';
import { Inbox, Search } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { index as analyticsIndex } from '@/routes/analytics';
import { index as conversationsIndex, show } from '@/routes/conversations';
import type {
    ConversationInboxFilters,
    ConversationInboxChannel,
    ConversationInboxPageProps,
    ConversationInboxHandoff,
    ConversationInboxRange,
    ConversationInboxSource,
    ConversationInboxStatus,
    Paginated,
} from '@/types';

const ranges: Array<{ value: ConversationInboxRange; label: string }> = [
    { value: 'all', label: 'All time' },
    { value: 'today', label: 'Today' },
    { value: '7d', label: 'Last 7 days' },
    { value: '30d', label: 'Last 30 days' },
];

const sources: Array<{ value: ConversationInboxSource; label: string }> = [
    { value: 'customer', label: 'Customer' },
    { value: 'preview', label: 'Preview' },
    { value: 'all', label: 'All sources' },
];

const handoffFilters: Array<{
    value: ConversationInboxHandoff;
    label: string;
}> = [
    { value: 'all', label: 'All conversations' },
    { value: 'needs_attention', label: 'Needs attention' },
    { value: 'human', label: 'Human active' },
];

const statusFilters: Array<{
    value: ConversationInboxStatus;
    label: string;
}> = [
    { value: 'all', label: 'All statuses' },
    { value: 'open', label: 'Open' },
    { value: 'pending', label: 'Pending' },
    { value: 'resolved', label: 'Resolved' },
    { value: 'closed', label: 'Closed' },
];

function formatDate(value: string | null): string {
    return value
        ? new Intl.DateTimeFormat(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(new Date(value))
        : '—';
}

function paginationLabel(label: string): string {
    return label.replace('&laquo;', 'Previous').replace('&raquo;', 'Next');
}

function pagination<T>(page: Paginated<T>) {
    return page.last_page > 1 ? (
        <nav
            className="flex flex-wrap items-center justify-center gap-2"
            aria-label="Conversations pagination"
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
                            {paginationLabel(link.label)}
                        </Link>
                    </Button>
                ) : (
                    <Button
                        key={`${link.label}-${index}`}
                        variant="outline"
                        size="sm"
                        disabled
                    >
                        {paginationLabel(link.label)}
                    </Button>
                ),
            )}
        </nav>
    ) : null;
}

export default function ConversationsIndex({
    filters,
    botOptions,
    conversations,
    handoffSummary,
    channelOptions,
    assignableUsers,
    tagOptions,
}: ConversationInboxPageProps) {
    const { t } = useTranslation();
    const { currentTeam } = usePage().props;
    const [search, setSearch] = useState(filters.search ?? '');

    if (!currentTeam) {
        return null;
    }

    const visit = (next: Partial<ConversationInboxFilters>) => {
        const nextFilters = { ...filters, ...next };

        router.get(
            conversationsIndex(currentTeam.slug, {
                query: {
                    bot: nextFilters.bot,
                    range: nextFilters.range,
                    source: nextFilters.source,
                    handoff: nextFilters.handoff,
                    channel: nextFilters.channel,
                    status: nextFilters.status,
                    assignee: nextFilters.assignee,
                    tag: nextFilters.tag,
                    search: nextFilters.search,
                },
            }).url,
        );
    };

    function submitSearch(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        visit({ search: search.trim() || null });
    }

    return (
        <>
            <Head title={t('navigation.conversations')} />

            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
                    <Heading
                        variant="small"
                        title={t('navigation.conversations')}
                        description="Review customer conversations handled by your Bots."
                    />

                    <Link
                        href={
                            analyticsIndex(currentTeam.slug, {
                                query: { bot: filters.bot },
                            }).url
                        }
                        className="text-sm text-muted-foreground hover:text-foreground hover:underline"
                    >
                        View analytics
                    </Link>
                </div>

                <div className="flex flex-col gap-3 rounded-xl border p-3 lg:flex-row lg:items-center">
                    <div className="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm">
                        <label htmlFor="conversation-bot" className="sr-only">
                            Filter by Bot
                        </label>
                        <select
                            id="conversation-bot"
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
                                    conversationsIndex(currentTeam.slug, {
                                        query: {
                                            bot: filters.bot,
                                            range: range.value,
                                            source: filters.source,
                                            handoff: filters.handoff,
                                            channel: filters.channel,
                                            status: filters.status,
                                            assignee: filters.assignee,
                                            tag: filters.tag,
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
                            htmlFor="conversation-channel"
                            className="sr-only"
                        >
                            Filter by channel
                        </label>
                        <select
                            id="conversation-channel"
                            value={filters.channel}
                            onChange={(event) =>
                                visit({
                                    channel: event.target
                                        .value as ConversationInboxChannel,
                                })
                            }
                            className="bg-transparent outline-none"
                        >
                            <option value="all">All channels</option>
                            {channelOptions.map((channel) => (
                                <option key={channel.key} value={channel.key}>
                                    {channel.name}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm">
                        <label
                            htmlFor="conversation-status"
                            className="sr-only"
                        >
                            Filter by status
                        </label>
                        <select
                            id="conversation-status"
                            value={filters.status}
                            onChange={(event) =>
                                visit({
                                    status: event.target
                                        .value as ConversationInboxStatus,
                                })
                            }
                            className="bg-transparent outline-none"
                        >
                            {statusFilters.map((status) => (
                                <option key={status.value} value={status.value}>
                                    {status.label}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm">
                        <label
                            htmlFor="conversation-assignee"
                            className="sr-only"
                        >
                            Filter by assignee
                        </label>
                        <select
                            id="conversation-assignee"
                            value={filters.assignee}
                            onChange={(event) =>
                                visit({ assignee: event.target.value })
                            }
                            className="bg-transparent outline-none"
                        >
                            <option value="all">All assignees</option>
                            <option value="unassigned">Unassigned</option>
                            <option value="me">Assigned to me</option>
                            {assignableUsers.map((member) => (
                                <option
                                    key={member.reference}
                                    value={member.reference}
                                >
                                    {member.name}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm">
                        <label htmlFor="conversation-tag" className="sr-only">
                            Filter by tag
                        </label>
                        <select
                            id="conversation-tag"
                            value={filters.tag ?? ''}
                            onChange={(event) =>
                                visit({ tag: event.target.value || null })
                            }
                            className="bg-transparent outline-none"
                        >
                            <option value="">All tags</option>
                            {tagOptions.map((tag) => (
                                <option key={tag.reference} value={tag.slug}>
                                    {tag.name}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm">
                        <label
                            htmlFor="conversation-handoff"
                            className="sr-only"
                        >
                            Filter by handoff status
                        </label>
                        <select
                            id="conversation-handoff"
                            value={filters.handoff}
                            onChange={(event) =>
                                visit({
                                    handoff: event.target
                                        .value as ConversationInboxHandoff,
                                })
                            }
                            className="bg-transparent outline-none"
                        >
                            {handoffFilters.map((handoff) => (
                                <option
                                    key={handoff.value}
                                    value={handoff.value}
                                >
                                    {handoff.label}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm">
                        <label
                            htmlFor="conversation-source"
                            className="sr-only"
                        >
                            Filter by source
                        </label>
                        <select
                            id="conversation-source"
                            value={filters.source}
                            onChange={(event) =>
                                visit({
                                    source: event.target
                                        .value as ConversationInboxSource,
                                })
                            }
                            className="bg-transparent outline-none"
                        >
                            {sources.map((source) => (
                                <option key={source.value} value={source.value}>
                                    {source.label}
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
                            htmlFor="conversation-search"
                            className="sr-only"
                        >
                            Search conversations
                        </label>
                        <input
                            id="conversation-search"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Search messages, Bot names, or reference"
                            className="min-w-0 flex-1 bg-transparent text-sm outline-none"
                        />
                        <Button type="submit" size="sm" variant="ghost">
                            Search
                        </Button>
                    </form>
                </div>

                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="rounded-xl border p-4">
                        <p className="text-sm text-muted-foreground">
                            Needs attention
                        </p>
                        <p className="mt-1 text-2xl font-semibold">
                            {handoffSummary.needsAttention}
                        </p>
                    </div>
                    <div className="rounded-xl border p-4">
                        <p className="text-sm text-muted-foreground">
                            Human active
                        </p>
                        <p className="mt-1 text-2xl font-semibold">
                            {handoffSummary.humanActive}
                        </p>
                    </div>
                </div>

                {conversations.data.length > 0 ? (
                    <div className="grid gap-3">
                        {conversations.data.map((conversation) => (
                            <Link
                                key={conversation.reference}
                                href={
                                    show([
                                        currentTeam.slug,
                                        conversation.reference,
                                    ]).url
                                }
                                className="grid gap-3 rounded-xl border p-4 transition-colors hover:bg-muted/40 md:grid-cols-[minmax(0,1fr)_auto]"
                            >
                                <div className="min-w-0">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <h2 className="font-medium">
                                            {conversation.bot.name}
                                        </h2>
                                        <span className="rounded-full bg-primary/10 px-2 py-0.5 text-xs text-primary">
                                            {conversation.channel === 'website'
                                                ? 'Website'
                                                : conversation.channel}
                                        </span>
                                        <span className="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">
                                            {conversation.source === 'widget'
                                                ? 'Customer'
                                                : 'Preview'}
                                        </span>
                                        {conversation.handoffStatus !== 'ai' ? (
                                            <span className="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-900">
                                                {conversation.handoffLabel}
                                            </span>
                                        ) : null}
                                        <span className="rounded-full bg-blue-100 px-2 py-0.5 text-xs text-blue-900">
                                            {
                                                conversation.conversationStatusLabel
                                            }
                                        </span>
                                        {conversation.assignee ? (
                                            <span className="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">
                                                {conversation.assignee.name}
                                            </span>
                                        ) : null}
                                    </div>
                                    <p className="mt-2 truncate text-sm text-muted-foreground">
                                        {conversation.subject ? (
                                            <span className="mr-2 font-medium text-foreground">
                                                {conversation.subject}
                                            </span>
                                        ) : null}
                                        {conversation.preview ||
                                            'No user message yet.'}
                                    </p>
                                </div>
                                <div className="flex items-center gap-3 text-sm text-muted-foreground md:flex-col md:items-end md:justify-center md:gap-1">
                                    <span>
                                        {formatDate(conversation.lastMessageAt)}
                                    </span>
                                    <span>
                                        {conversation.messageCount}{' '}
                                        {conversation.messageCount === 1
                                            ? 'message'
                                            : 'messages'}
                                    </span>
                                </div>
                            </Link>
                        ))}
                    </div>
                ) : (
                    <div className="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed px-6 py-16 text-center">
                        <div className="flex size-12 items-center justify-center rounded-full bg-primary/10 text-primary">
                            <Inbox className="size-6" />
                        </div>
                        <div className="space-y-1">
                            <h2 className="font-medium">
                                {filters.search || filters.source !== 'customer'
                                    ? 'No conversations match these filters.'
                                    : 'No conversations yet'}
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                Customer conversations will appear here after
                                visitors start using your Bots.
                            </p>
                        </div>
                    </div>
                )}

                {pagination(conversations)}
            </div>
        </>
    );
}
