import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, Plus, XCircle } from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { index as botsIndex } from '@/routes/bots';
import { create, destroy, run, show } from '@/routes/bots/tests';
import type { BotTestPageProps, BotTestRunStatus } from '@/types';

function statusLabel(status: BotTestRunStatus | null): string {
    return status === 'passed'
        ? 'Passed'
        : status === 'failed'
          ? 'Failed'
          : status === 'error'
            ? 'Error'
            : 'Not run';
}

function statusVariant(status: BotTestRunStatus | null) {
    return status === 'passed'
        ? 'default'
        : status === 'failed' || status === 'error'
          ? 'destructive'
          : 'secondary';
}

export default function BotTestsIndex({
    bot,
    scenarios,
    summary,
}: BotTestPageProps) {
    const { currentTeam } = usePage().props;

    if (!currentTeam) {
        return null;
    }

    const botRoute = [currentTeam.slug, bot.id] as [string, number];

    return (
        <>
            <Head title={`Tests · ${bot.name}`} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                    <div className="flex items-start gap-3">
                        <Button variant="ghost" size="icon" asChild>
                            <Link
                                href={botsIndex(currentTeam.slug).url}
                                aria-label="Back to bots"
                            >
                                <ArrowLeft />
                            </Link>
                        </Button>
                        <Heading
                            variant="small"
                            title="Bot tests"
                            description={`${bot.name} · saved regression scenarios`}
                        />
                    </div>
                    <Button asChild>
                        <Link href={create(botRoute).url}>
                            <Plus />
                            New test
                        </Link>
                    </Button>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    {[
                        ['Total', summary.total],
                        ['Enabled', summary.enabled],
                        ['Passing', summary.passing],
                        ['Failing', summary.failing],
                        ['Not run', summary.not_run],
                    ].map(([label, value]) => (
                        <Card key={label}>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm font-normal text-muted-foreground">
                                    {label}
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="text-2xl font-semibold">
                                {value}
                            </CardContent>
                        </Card>
                    ))}
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Saved scenarios</CardTitle>
                        <p className="text-sm text-muted-foreground">
                            Run these checks against the current Bot
                            configuration. Test runs are synchronous and
                            isolated from production history.
                        </p>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {scenarios.data.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No saved tests yet.
                            </p>
                        ) : (
                            scenarios.data.map((scenario) => {
                                const status =
                                    scenario.latestRun?.status ?? null;
                                const route = [
                                    currentTeam.slug,
                                    bot.id,
                                    scenario.publicId,
                                ] as [string, number, string];

                                return (
                                    <div
                                        key={scenario.publicId}
                                        className="flex flex-col justify-between gap-4 rounded-lg border p-4 sm:flex-row sm:items-center"
                                    >
                                        <div className="min-w-0 space-y-1">
                                            <Link
                                                href={show(route).url}
                                                className="font-medium hover:underline"
                                            >
                                                {scenario.name}
                                            </Link>
                                            <p className="truncate text-sm text-muted-foreground">
                                                {scenario.inputMessage}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {scenario.expectations.length}{' '}
                                                expectation(s) ·{' '}
                                                {scenario.runCount} run(s)
                                            </p>
                                        </div>
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Badge
                                                variant={statusVariant(status)}
                                            >
                                                {status === 'passed' ? (
                                                    <CheckCircle2 />
                                                ) : status === 'failed' ||
                                                  status === 'error' ? (
                                                    <XCircle />
                                                ) : null}
                                                {statusLabel(status)}
                                            </Badge>
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    router.post(
                                                        run(route).url,
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Run
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                asChild
                                            >
                                                <Link href={show(route).url}>
                                                    Details
                                                </Link>
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() => {
                                                    if (
                                                        window.confirm(
                                                            'Delete this saved test?',
                                                        )
                                                    ) {
                                                        router.delete(
                                                            destroy(route).url,
                                                        );
                                                    }
                                                }}
                                            >
                                                Delete
                                            </Button>
                                        </div>
                                    </div>
                                );
                            })
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}
