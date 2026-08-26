import { Head, Link, router, usePage } from '@inertiajs/react';
import { BriefcaseBusiness, List, Plus, Settings2 } from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { create, index as dealsIndex, pipelines, show } from '@/routes/deals';
import type { DealIndexPageProps, DealListItem } from '@/types';

function money(deal: DealListItem): string {
    return deal.valueAmount === null
        ? '—'
        : `${deal.currency} ${Number(deal.valueAmount).toLocaleString()}`;
}

export default function DealsIndex({
    view,
    filters,
    pipelines: pipelineOptions,
    ownerOptions,
    metrics,
    stages,
    deals,
}: DealIndexPageProps) {
    const { currentTeam } = usePage().props;

    if (!currentTeam) {
        return null;
    }

    const items = Array.isArray(deals) ? deals : deals.data;
    const selected = pipelineOptions.find(
        (pipeline) => pipeline.id === filters.pipelineId,
    );
    const go = (next: Record<string, string | number | null>) =>
        router.get(
            dealsIndex(currentTeam.slug).url,
            { ...filters, ...next, view },
            { preserveState: true, replace: true },
        );

    return (
        <>
            <Head title="Deals" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
                    <Heading
                        variant="small"
                        title="Deals"
                        description="Manage your sales pipeline and customer revenue."
                    />
                    <div className="flex gap-2">
                        <Link href={pipelines(currentTeam.slug).url}>
                            <Button variant="outline">
                                <Settings2 className="mr-2 size-4" />
                                Pipelines
                            </Button>
                        </Link>
                        <Link href={create(currentTeam.slug).url}>
                            <Button>
                                <Plus className="mr-2 size-4" />
                                New deal
                            </Button>
                        </Link>
                    </div>
                </div>
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    {[
                        ['Open', metrics.openCount],
                        ['Won', metrics.wonCount],
                        ['Lost', metrics.lostCount],
                        ['Overdue', metrics.overdueCount],
                        [
                            'Win rate',
                            metrics.winRate === null
                                ? '—'
                                : `${metrics.winRate}%`,
                        ],
                    ].map(([label, value]) => (
                        <Card key={String(label)}>
                            <CardContent className="p-4">
                                <p className="text-sm text-muted-foreground">
                                    {label}
                                </p>
                                <p className="mt-1 text-2xl font-semibold">
                                    {value}
                                </p>
                            </CardContent>
                        </Card>
                    ))}
                </div>
                <Card>
                    <CardContent className="flex flex-col gap-3 p-4 md:flex-row">
                        <input
                            value={filters.search ?? ''}
                            onChange={(event) =>
                                go({ search: event.target.value || null })
                            }
                            placeholder="Search deals or customers"
                            className="rounded-lg border bg-transparent px-3 py-2 text-sm md:w-72"
                        />
                        <select
                            value={filters.pipelineId ?? selected?.id ?? ''}
                            onChange={(event) =>
                                go({
                                    pipeline_id: event.target.value || null,
                                    stage_id: null,
                                })
                            }
                            className="rounded-lg border bg-transparent px-3 py-2 text-sm"
                        >
                            <option value="">All pipelines</option>
                            {pipelineOptions.map((pipeline) => (
                                <option key={pipeline.id} value={pipeline.id}>
                                    {pipeline.name}
                                </option>
                            ))}
                        </select>
                        <select
                            value={filters.status}
                            onChange={(event) =>
                                go({ status: event.target.value })
                            }
                            className="rounded-lg border bg-transparent px-3 py-2 text-sm"
                        >
                            <option value="all">All statuses</option>
                            <option value="open">Open</option>
                            <option value="won">Won</option>
                            <option value="lost">Lost</option>
                        </select>
                        <select
                            value={filters.ownerUserId ?? ''}
                            onChange={(event) =>
                                go({
                                    owner_user_id: event.target.value || null,
                                })
                            }
                            className="rounded-lg border bg-transparent px-3 py-2 text-sm"
                        >
                            <option value="">All owners</option>
                            {ownerOptions.map((owner) => (
                                <option key={owner.id} value={owner.id}>
                                    {owner.name}
                                </option>
                            ))}
                        </select>
                        <div className="ml-auto flex gap-2">
                            <Button
                                variant={
                                    view === 'board' ? 'default' : 'outline'
                                }
                                size="sm"
                                onClick={() => go({ view: 'board' })}
                            >
                                <BriefcaseBusiness className="mr-2 size-4" />
                                Board
                            </Button>
                            <Button
                                variant={
                                    view === 'list' ? 'default' : 'outline'
                                }
                                size="sm"
                                onClick={() => go({ view: 'list' })}
                            >
                                <List className="mr-2 size-4" />
                                List
                            </Button>
                        </div>
                    </CardContent>
                </Card>
                {view === 'list' ? (
                    <Card>
                        <CardHeader>
                            <CardTitle>All deals</CardTitle>
                        </CardHeader>
                        <CardContent className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead>
                                    <tr className="border-b text-muted-foreground">
                                        <th className="p-3">Deal</th>
                                        <th className="p-3">Customer</th>
                                        <th className="p-3">Stage</th>
                                        <th className="p-3">Value</th>
                                        <th className="p-3">Owner</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {items.map((deal) => (
                                        <tr
                                            key={deal.id}
                                            className="border-b last:border-0"
                                        >
                                            <td className="p-3">
                                                <Link
                                                    className="font-medium hover:underline"
                                                    href={
                                                        show([
                                                            currentTeam.slug,
                                                            deal.id,
                                                        ]).url
                                                    }
                                                >
                                                    {deal.title}
                                                </Link>
                                            </td>
                                            <td className="p-3">
                                                {deal.customer?.name ?? '—'}
                                            </td>
                                            <td className="p-3">
                                                <Badge variant="outline">
                                                    {deal.stage.name}
                                                </Badge>
                                            </td>
                                            <td className="p-3">
                                                {money(deal)}
                                            </td>
                                            <td className="p-3">
                                                {deal.owner?.name ??
                                                    'Unassigned'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="grid gap-4 overflow-x-auto pb-2 md:grid-cols-2 xl:grid-cols-4">
                        {(selected?.stages ?? stages).map((stage) => (
                            <div
                                key={stage.id}
                                className="min-w-64 rounded-xl bg-muted/40 p-3"
                            >
                                <div className="mb-3 flex items-center justify-between">
                                    <h2 className="font-medium">
                                        {stage.name}
                                    </h2>
                                    <Badge variant="secondary">
                                        {
                                            items.filter(
                                                (deal) =>
                                                    deal.stageId === stage.id,
                                            ).length
                                        }
                                    </Badge>
                                </div>
                                <div className="grid gap-3">
                                    {items
                                        .filter(
                                            (deal) => deal.stageId === stage.id,
                                        )
                                        .map((deal) => (
                                            <Link
                                                key={deal.id}
                                                href={
                                                    show([
                                                        currentTeam.slug,
                                                        deal.id,
                                                    ]).url
                                                }
                                            >
                                                <Card className="transition hover:border-primary">
                                                    <CardContent className="p-4">
                                                        <p className="font-medium">
                                                            {deal.title}
                                                        </p>
                                                        <p className="mt-1 text-sm text-muted-foreground">
                                                            {deal.customer
                                                                ?.name ??
                                                                'No customer'}
                                                        </p>
                                                        <p className="mt-3 text-sm font-medium">
                                                            {money(deal)}
                                                        </p>
                                                        {deal.overdue ? (
                                                            <Badge
                                                                className="mt-2"
                                                                variant="destructive"
                                                            >
                                                                Overdue
                                                            </Badge>
                                                        ) : null}
                                                    </CardContent>
                                                </Card>
                                            </Link>
                                        ))}
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}
