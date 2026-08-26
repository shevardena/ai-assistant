import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    CheckCircle2,
    Clock3,
    ListChecks,
    Search,
    XCircle,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { show as actionShow, index as actionsIndex } from '@/routes/actions';
import { show as botShow } from '@/routes/bots';
import { show as conversationShow } from '@/routes/conversations';
import type {
    ActionHistoryFilters,
    ActionHistoryItem,
    ActionHistoryPageProps,
    ActionHistoryRange,
    ActionHistoryStatus,
} from '@/types';

const ranges: Array<{ value: ActionHistoryRange; label: string }> = [
    { value: 'today', label: 'Today' },
    { value: '7d', label: 'Last 7 days' },
    { value: '30d', label: 'Last 30 days' },
    { value: '90d', label: 'Last 90 days' },
    { value: 'all', label: 'All time' },
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

function duration(value: number | null): string {
    return value === null ? '—' : `${number(value)} ms`;
}

function ActionRow({
    action,
    teamSlug,
}: {
    action: ActionHistoryItem;
    teamSlug: string;
}) {
    return (
        <tr className="border-b last:border-0">
            <td className="px-3 py-4 align-top">
                <Link
                    href={actionShow([teamSlug, action.actionReference]).url}
                    className="flex w-fit items-center gap-2 font-medium hover:underline"
                >
                    <ListChecks className="size-4 text-muted-foreground" />
                    {action.label}
                </Link>
                {action.errorSummary ? (
                    <p className="mt-1 max-w-sm text-xs text-destructive">
                        {action.errorSummary}
                    </p>
                ) : null}
            </td>
            <td className="px-3 py-4 align-top text-sm text-muted-foreground">
                <Link
                    href={botShow([teamSlug, action.bot.id]).url}
                    className="text-foreground hover:underline"
                >
                    {action.bot.name}
                </Link>
            </td>
            <td className="px-3 py-4 align-top">
                <Badge variant={statusVariant(action.status)}>
                    {action.statusLabel}
                </Badge>
            </td>
            <td className="px-3 py-4 align-top text-sm text-muted-foreground">
                <div>{formatDate(action.createdAt)}</div>
                {action.conversationReference ? (
                    <Link
                        href={
                            conversationShow([
                                teamSlug,
                                action.conversationReference,
                            ]).url
                        }
                        className="mt-1 block w-fit text-xs hover:text-foreground hover:underline"
                    >
                        View conversation
                    </Link>
                ) : null}
            </td>
            <td className="px-3 py-4 text-right text-sm text-muted-foreground">
                {duration(action.durationMs)}
            </td>
        </tr>
    );
}

export default function ActionsIndex({
    filters,
    botOptions,
    actionOptions,
    statusOptions,
    summary,
    actions,
}: ActionHistoryPageProps) {
    const { t } = useTranslation();
    const { currentTeam } = usePage().props;
    const [search, setSearch] = useState(filters.search ?? '');

    if (!currentTeam) {
        return null;
    }

    const team = currentTeam;
    const visit = (next: Partial<ActionHistoryFilters>) => {
        const nextFilters = { ...filters, ...next };

        router.get(
            actionsIndex(team.slug, {
                query: {
                    bot: nextFilters.bot,
                    range: nextFilters.range,
                    action: nextFilters.action,
                    status: nextFilters.status,
                    search: nextFilters.search,
                },
            }).url,
        );
    };

    function submitSearch(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        visit({ search: search.trim() || null });
    }

    const cards = [
        { label: 'Total actions', value: summary.total, icon: ListChecks },
        { label: 'Completed', value: summary.completed, icon: CheckCircle2 },
        { label: 'Failed', value: summary.failed, icon: XCircle },
        { label: 'Cancelled', value: summary.cancelled, icon: XCircle },
        { label: 'Pending', value: summary.pending, icon: Clock3 },
    ] as const;

    return (
        <>
            <Head title={t('navigation.action_history')} />

            <div className="flex flex-col gap-6 p-4 md:p-6">
                <Heading
                    variant="small"
                    title={t('navigation.action_history')}
                    description="Review safe summaries of actions performed by your Bots."
                />

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                    {cards.map((card) => {
                        const Icon = card.icon;

                        return (
                            <Card key={card.label} className="gap-3 py-4">
                                <CardContent className="flex items-center justify-between px-4">
                                    <div>
                                        <p className="text-sm text-muted-foreground">
                                            {card.label}
                                        </p>
                                        <p className="mt-1 text-2xl font-semibold tracking-tight">
                                            {number(card.value)}
                                        </p>
                                    </div>
                                    <Icon className="size-5 text-muted-foreground" />
                                </CardContent>
                            </Card>
                        );
                    })}
                </div>

                <div className="flex flex-col gap-3 rounded-xl border p-3 lg:flex-row lg:items-center">
                    <select
                        aria-label="Filter by date range"
                        value={filters.range}
                        onChange={(event) =>
                            visit({
                                range: event.target.value as ActionHistoryRange,
                            })
                        }
                        className="rounded-lg border bg-transparent px-3 py-2 text-sm outline-none"
                    >
                        {ranges.map((range) => (
                            <option key={range.value} value={range.value}>
                                {range.label}
                            </option>
                        ))}
                    </select>

                    <select
                        aria-label="Filter by Bot"
                        value={filters.bot ?? ''}
                        onChange={(event) =>
                            visit({ bot: event.target.value || null })
                        }
                        className="rounded-lg border bg-transparent px-3 py-2 text-sm outline-none"
                    >
                        <option value="">All Bots</option>
                        {botOptions.map((bot) => (
                            <option key={bot.slug} value={bot.slug}>
                                {bot.name}
                            </option>
                        ))}
                    </select>

                    <select
                        aria-label="Filter by action"
                        value={filters.action ?? ''}
                        onChange={(event) =>
                            visit({ action: event.target.value || null })
                        }
                        className="rounded-lg border bg-transparent px-3 py-2 text-sm outline-none"
                    >
                        <option value="">All actions</option>
                        {actionOptions.map((action) => (
                            <option key={action.key} value={action.key}>
                                {action.label}
                            </option>
                        ))}
                    </select>

                    <select
                        aria-label="Filter by status"
                        value={filters.status ?? ''}
                        onChange={(event) =>
                            visit({
                                status: (event.target.value ||
                                    null) as ActionHistoryStatus | null,
                            })
                        }
                        className="rounded-lg border bg-transparent px-3 py-2 text-sm outline-none"
                    >
                        <option value="">All statuses</option>
                        {statusOptions.map((status) => (
                            <option key={status.key} value={status.key}>
                                {status.label}
                            </option>
                        ))}
                    </select>

                    <form
                        onSubmit={submitSearch}
                        className="flex min-w-0 flex-1 items-center gap-2 rounded-lg border px-3 py-2"
                    >
                        <Search className="size-4 shrink-0 text-muted-foreground" />
                        <input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Search action, Bot, or reference"
                            className="min-w-0 flex-1 bg-transparent text-sm outline-none"
                        />
                        <Button type="submit" size="sm" variant="ghost">
                            Search
                        </Button>
                    </form>
                </div>

                {summary.successRate !== null ? (
                    <p className="text-sm text-muted-foreground">
                        Terminal success rate:{' '}
                        <span className="font-medium text-foreground">
                            {summary.successRate.toFixed(1)}%
                        </span>
                    </p>
                ) : null}

                <Card className="overflow-hidden py-0">
                    <CardContent className="overflow-x-auto px-0">
                        {actions.data.length > 0 ? (
                            <table className="w-full min-w-[50rem] text-left">
                                <thead className="border-b text-xs text-muted-foreground">
                                    <tr>
                                        <th className="px-3 py-3 font-medium">
                                            Action
                                        </th>
                                        <th className="px-3 py-3 font-medium">
                                            Bot
                                        </th>
                                        <th className="px-3 py-3 font-medium">
                                            Status
                                        </th>
                                        <th className="px-3 py-3 font-medium">
                                            Time
                                        </th>
                                        <th className="px-3 py-3 text-right font-medium">
                                            Duration
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {actions.data.map((action) => (
                                        <ActionRow
                                            key={action.actionReference}
                                            action={action}
                                            teamSlug={team.slug}
                                        />
                                    ))}
                                </tbody>
                            </table>
                        ) : (
                            <div className="flex flex-col items-center gap-2 px-6 py-16 text-center">
                                <ListChecks className="size-8 text-muted-foreground" />
                                <p className="font-medium">
                                    {filters.search ||
                                    filters.action ||
                                    filters.status ||
                                    filters.bot
                                        ? 'No actions match these filters.'
                                        : 'No actions yet'}
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    Actions performed by your Bots will appear
                                    here.
                                </p>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {actions.last_page > 1 ? (
                    <nav
                        className="flex flex-wrap items-center justify-center gap-2"
                        aria-label="Action history pagination"
                    >
                        {actions.links.map((link, index) =>
                            link.url ? (
                                <Button
                                    key={`${link.label}-${index}`}
                                    variant={
                                        link.active ? 'default' : 'outline'
                                    }
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
                ) : null}
            </div>
        </>
    );
}
