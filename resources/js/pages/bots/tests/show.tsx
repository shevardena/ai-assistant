import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, Check, Pencil, Play, X } from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { index, edit, run } from '@/routes/bots/tests';
import type { BotTestDetailPageProps, BotTestExpectation } from '@/types';

function expectationLabel(expectation: BotTestExpectation): string {
    return `${expectation.type.replaceAll('_', ' ')}: ${expectation.value}`;
}

export default function BotTestsShow({
    bot,
    scenario,
    runs,
}: BotTestDetailPageProps) {
    const { currentTeam } = usePage().props;

    if (!currentTeam) {
        return null;
    }

    const route = [currentTeam.slug, bot.id, scenario.publicId] as [
        string,
        number,
        string,
    ];
    const latest = scenario.latestRun;
    const results = latest?.resultSummary.expectation_results ?? [];

    return (
        <>
            <Head title={`${scenario.name} · ${bot.name}`} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                    <div className="flex items-start gap-3">
                        <Button variant="ghost" size="icon" asChild>
                            <Link
                                href={index([currentTeam.slug, bot.id]).url}
                                aria-label="Back to Bot tests"
                            >
                                <ArrowLeft />
                            </Link>
                        </Button>
                        <Heading
                            variant="small"
                            title={scenario.name}
                            description={`${bot.name} · ${scenario.isEnabled ? 'Enabled' : 'Disabled'}`}
                        />
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" asChild>
                            <Link href={edit(route).url}>
                                <Pencil />
                                Edit
                            </Link>
                        </Button>
                        <Button onClick={() => router.post(run(route).url)}>
                            <Play />
                            Run test
                        </Button>
                    </div>
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Input</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-sm whitespace-pre-wrap">
                                {scenario.inputMessage}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Expectations</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {scenario.expectations.map((expectation, index) => {
                                const result = results[index];

                                return (
                                    <div
                                        key={`${expectation.type}-${index}`}
                                        className="flex items-start gap-2 text-sm"
                                    >
                                        {result ? (
                                            result.passed ? (
                                                <Check className="mt-0.5 text-green-600" />
                                            ) : (
                                                <X className="mt-0.5 text-red-600" />
                                            )
                                        ) : (
                                            <span className="mt-0.5 size-4 rounded-full border" />
                                        )}
                                        <span>
                                            {expectationLabel(expectation)}
                                        </span>
                                    </div>
                                );
                            })}
                            {scenario.expectations.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No expectations configured.
                                </p>
                            ) : null}
                        </CardContent>
                    </Card>
                </div>

                {latest ? (
                    <Card>
                        <CardHeader className="flex-row items-center justify-between">
                            <CardTitle>Latest result</CardTitle>
                            <Badge
                                variant={
                                    latest.status === 'passed'
                                        ? 'default'
                                        : 'destructive'
                                }
                            >
                                {latest.status}
                            </Badge>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {latest.responseText ? (
                                <p className="text-sm whitespace-pre-wrap">
                                    {latest.responseText}
                                </p>
                            ) : null}
                            <div className="grid gap-4 text-sm sm:grid-cols-3">
                                <div>
                                    <p className="text-muted-foreground">
                                        Tools called
                                    </p>
                                    <p>
                                        {latest.resultSummary.tools_called?.join(
                                            ', ',
                                        ) || 'None'}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-muted-foreground">
                                        Blocks returned
                                    </p>
                                    <p>
                                        {latest.resultSummary.blocks_returned?.join(
                                            ', ',
                                        ) || 'None'}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-muted-foreground">
                                        Action proposals
                                    </p>
                                    <p>
                                        {latest.resultSummary.action_proposals?.join(
                                            ', ',
                                        ) || 'None'}
                                    </p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                ) : null}

                <Card>
                    <CardHeader>
                        <CardTitle>Recent runs</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        {runs.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No runs yet.
                            </p>
                        ) : (
                            runs.map((run) => (
                                <div
                                    key={run.publicId}
                                    className="flex items-center justify-between rounded border px-3 py-2 text-sm"
                                >
                                    <span>
                                        {run.startedAt
                                            ? new Date(
                                                  run.startedAt,
                                              ).toLocaleString()
                                            : 'Unknown time'}
                                    </span>
                                    <Badge
                                        variant={
                                            run.status === 'passed'
                                                ? 'default'
                                                : 'destructive'
                                        }
                                    >
                                        {run.status}
                                    </Badge>
                                </div>
                            ))
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}
