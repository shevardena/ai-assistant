import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    Check,
    CircleAlert,
    CircleOff,
    Database,
    ExternalLink,
    Radio,
    Settings2,
    Zap,
} from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { show as botShow } from '@/routes/bots';
import type {
    BotCapability,
    BotCapabilityGroup,
    CapabilityKind,
    CapabilityStatus,
} from '@/types';

type Props = {
    bot: {
        id: number;
        name: string;
        slug: string;
    };
    groups: BotCapabilityGroup[];
};

function statusLabel(status: CapabilityStatus): string {
    if (status === 'ready') {
        return 'Ready';
    }

    if (status === 'needs_configuration') {
        return 'Needs configuration';
    }

    if (status === 'disabled') {
        return 'Disabled';
    }

    return 'Unavailable';
}

function statusVariant(
    status: CapabilityStatus,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (status === 'ready') {
        return 'default';
    }

    if (status === 'needs_configuration') {
        return 'outline';
    }

    if (status === 'disabled') {
        return 'destructive';
    }

    return 'secondary';
}

function kindLabel(kind: CapabilityKind): string {
    return {
        data: 'Data',
        live: 'Live read',
        action: 'Action',
    }[kind];
}

function KindIcon({ kind }: { kind: CapabilityKind }) {
    if (kind === 'data') {
        return <Database className="size-4" />;
    }

    if (kind === 'live') {
        return <Radio className="size-4" />;
    }

    return <Zap className="size-4" />;
}

function StatusIcon({ status }: { status: CapabilityStatus }) {
    if (status === 'ready') {
        return <Check className="size-4" />;
    }

    if (status === 'disabled') {
        return <CircleOff className="size-4" />;
    }

    return <CircleAlert className="size-4" />;
}

function CapabilityCard({ capability }: { capability: BotCapability }) {
    const datasets = capability.details.datasets ?? [];

    return (
        <Card className="h-full">
            <CardHeader className="gap-3">
                <div className="flex items-start justify-between gap-3">
                    <div className="flex min-w-0 items-start gap-3">
                        <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                            <KindIcon kind={capability.kind} />
                        </div>
                        <div className="min-w-0">
                            <CardTitle className="text-base">
                                {capability.label}
                            </CardTitle>
                            <p className="mt-1 text-xs text-muted-foreground">
                                {kindLabel(capability.kind)}
                            </p>
                        </div>
                    </div>
                    <Badge
                        variant={statusVariant(capability.status)}
                        className="shrink-0"
                    >
                        <StatusIcon status={capability.status} />
                        {statusLabel(capability.status)}
                    </Badge>
                </div>
                <CardDescription>{capability.description}</CardDescription>
            </CardHeader>
            <CardContent className="flex h-full flex-col gap-4">
                <p className="text-sm text-muted-foreground">
                    {capability.statusMessage}
                </p>

                {datasets.length > 0 ? (
                    <div className="space-y-2">
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Uses datasets
                        </p>
                        <div className="flex flex-wrap gap-2">
                            {datasets.map((dataset) => (
                                <Badge key={dataset.slug} variant="secondary">
                                    {dataset.name}
                                </Badge>
                            ))}
                        </div>
                    </div>
                ) : null}

                {capability.details.operationName ||
                capability.details.dataSourceName ? (
                    <div className="grid gap-2 rounded-lg border bg-muted/20 p-3 text-sm">
                        {capability.details.operationName ? (
                            <div className="flex justify-between gap-3">
                                <span className="text-muted-foreground">
                                    Operation
                                </span>
                                <span className="text-right font-medium">
                                    {capability.details.operationName}
                                </span>
                            </div>
                        ) : null}
                        {capability.details.dataSourceName ? (
                            <div className="flex justify-between gap-3">
                                <span className="text-muted-foreground">
                                    Source
                                </span>
                                <span className="text-right font-medium">
                                    {capability.details.dataSourceName}
                                </span>
                            </div>
                        ) : null}
                    </div>
                ) : null}

                {capability.requiresConfirmation ? (
                    <p className="text-xs text-muted-foreground">
                        Requires visitor confirmation before execution.
                    </p>
                ) : null}

                <div className="mt-auto pt-1">
                    {capability.configureUrl ? (
                        <Button variant="outline" size="sm" asChild>
                            <Link href={capability.configureUrl}>
                                <Settings2 />
                                {capability.configureLabel ?? 'Configure'}
                                <ExternalLink className="ml-auto" />
                            </Link>
                        </Button>
                    ) : null}
                </div>
            </CardContent>
        </Card>
    );
}

export default function BotCapabilities({ bot, groups }: Props) {
    const { currentTeam } = usePage().props;

    if (!currentTeam) {
        return null;
    }

    return (
        <>
            <Head title={`${bot.name} capabilities`} />

            <h1 className="sr-only">{bot.name} capabilities</h1>

            <div className="flex flex-col gap-8 p-4 md:p-6">
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                    <div className="flex items-start gap-3">
                        <Button variant="ghost" size="icon" asChild>
                            <Link
                                href={botShow([currentTeam.slug, bot.id]).url}
                                aria-label={`Back to ${bot.name}`}
                            >
                                <ArrowLeft />
                            </Link>
                        </Button>
                        <Heading
                            variant="small"
                            title="Capabilities"
                            description={`Configure what ${bot.name} can do.`}
                        />
                    </div>
                </div>

                {groups.map((group) => (
                    <section key={group.key} className="space-y-4">
                        <div>
                            <h2 className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                                {group.label}
                            </h2>
                        </div>
                        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                            {group.capabilities.map((capability) => (
                                <CapabilityCard
                                    key={capability.key}
                                    capability={capability}
                                />
                            ))}
                        </div>
                    </section>
                ))}
            </div>
        </>
    );
}
