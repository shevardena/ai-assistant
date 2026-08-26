import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    FlaskConical,
    Palette,
    Pencil,
    RadioTower,
    Settings2,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import BotChatPreview from '@/components/bot-chat-preview';
import BotDatasetAssignmentForm from '@/components/bot-dataset-assignment-form';
import BotDomainManager from '@/components/bot-domain-manager';
import BotEmbedCard from '@/components/bot-embed-card';
import DeleteBotDialog from '@/components/delete-bot-dialog';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { botStatusVariant, statusDescription, statusLabel } from '@/lib/status';
import { edit, index } from '@/routes/bots';
import { show as showCapabilities } from '@/routes/bots/capabilities';
import { index as channelsIndex } from '@/routes/bots/channels';
import { update as updateDatasets } from '@/routes/bots/datasets';
import { edit as editDesign } from '@/routes/bots/design';
import { show as setupShow } from '@/routes/bots/setup';
import { index as testsIndex } from '@/routes/bots/tests';
import type { Bot } from '@/types';

type Props = {
    bot: Bot;
};

function formatDate(value: string | null): string {
    return value
        ? new Intl.DateTimeFormat(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(new Date(value))
        : '—';
}

export default function BotsShow({ bot }: Props) {
    const { currentTeam } = usePage().props;
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);

    if (!currentTeam) {
        return null;
    }

    return (
        <>
            <Head title={bot.name} />

            <h1 className="sr-only">{bot.name}</h1>

            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                    <div className="flex items-start gap-3">
                        <Button variant="ghost" size="icon" asChild>
                            <Link
                                href={index(currentTeam.slug).url}
                                aria-label="Back to bots"
                            >
                                <ArrowLeft />
                            </Link>
                        </Button>
                        <Heading
                            variant="small"
                            title={bot.name}
                            description={`/${bot.slug}`}
                        />
                    </div>

                    <div className="flex items-center gap-2">
                        <Button variant="outline" asChild>
                            <Link
                                href={
                                    channelsIndex([currentTeam.slug, bot.id])
                                        .url
                                }
                            >
                                <RadioTower />
                                Channels
                            </Link>
                        </Button>
                        <Button variant="outline" asChild>
                            <Link
                                href={setupShow([currentTeam.slug, bot.id]).url}
                            >
                                <Settings2 />
                                Setup
                            </Link>
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href={edit([currentTeam.slug, bot.id]).url}>
                                <Pencil />
                                Edit
                            </Link>
                        </Button>
                        <Button variant="outline" asChild>
                            <Link
                                href={
                                    testsIndex([currentTeam.slug, bot.id]).url
                                }
                            >
                                <FlaskConical />
                                Tests
                            </Link>
                        </Button>
                        <Button variant="outline" asChild>
                            <Link
                                href={
                                    editDesign([currentTeam.slug, bot.id]).url
                                }
                            >
                                <Palette />
                                Design
                            </Link>
                        </Button>
                        <Button variant="outline" asChild>
                            <Link
                                href={
                                    showCapabilities([currentTeam.slug, bot.id])
                                        .url
                                }
                            >
                                <Settings2 />
                                Capabilities
                            </Link>
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() => setDeleteDialogOpen(true)}
                            data-test="bot-show-delete-button"
                        >
                            <Trash2 />
                            Delete
                        </Button>
                    </div>
                </div>

                <div className="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(16rem,1fr)]">
                    <Card id="domains">
                        <CardHeader>
                            <div className="flex items-center justify-between gap-4">
                                <CardTitle>Overview</CardTitle>
                                <Badge variant={botStatusVariant(bot.status)}>
                                    {statusLabel(bot.status)}
                                </Badge>
                                {statusDescription(bot.status) ? (
                                    <p className="text-xs text-muted-foreground">
                                        {statusDescription(bot.status)}
                                    </p>
                                ) : null}
                            </div>
                        </CardHeader>
                        <CardContent className="grid gap-6 sm:grid-cols-2">
                            <div className="space-y-1">
                                <p className="text-sm text-muted-foreground">
                                    Default language
                                </p>
                                <p className="font-medium">
                                    {bot.defaultLanguage}
                                </p>
                            </div>
                            <div className="space-y-1">
                                <p className="text-sm text-muted-foreground">
                                    Slug
                                </p>
                                <p className="font-medium">{bot.slug}</p>
                            </div>
                            <div className="space-y-1 sm:col-span-2">
                                <p className="text-sm text-muted-foreground">
                                    Instructions
                                </p>
                                <p className="whitespace-pre-wrap">
                                    {bot.instructions ||
                                        'No instructions configured.'}
                                </p>
                            </div>
                            <div className="space-y-1">
                                <p className="text-sm text-muted-foreground">
                                    Welcome message
                                </p>
                                <p className="whitespace-pre-wrap">
                                    {bot.welcomeMessage || 'Not configured.'}
                                </p>
                            </div>
                            <div className="space-y-1">
                                <p className="text-sm text-muted-foreground">
                                    Fallback message
                                </p>
                                <p className="whitespace-pre-wrap">
                                    {bot.fallbackMessage || 'Not configured.'}
                                </p>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4 text-sm">
                            <div className="space-y-1">
                                <p className="text-muted-foreground">Created</p>
                                <p>{formatDate(bot.createdAt)}</p>
                            </div>
                            <div className="space-y-1">
                                <p className="text-muted-foreground">Updated</p>
                                <p>{formatDate(bot.updatedAt)}</p>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Datasets</CardTitle>
                        <p className="text-sm text-muted-foreground">
                            Choose the current-team datasets this bot can use
                            for search.
                        </p>
                    </CardHeader>
                    <CardContent>
                        <BotDatasetAssignmentForm
                            action={updateDatasets.form([
                                currentTeam.slug,
                                bot.id,
                            ])}
                            datasets={bot.datasets}
                            currentTeamSlug={currentTeam.slug}
                        />
                    </CardContent>
                </Card>

                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Allowed domains</CardTitle>
                            <p className="text-sm text-muted-foreground">
                                The widget accepts chat requests only from these
                                exact hosts.
                            </p>
                        </CardHeader>
                        <CardContent>
                            <BotDomainManager
                                domains={bot.domains}
                                currentTeamSlug={currentTeam.slug}
                                botId={bot.id}
                            />
                        </CardContent>
                    </Card>

                    <div id="embed">
                        <BotEmbedCard widget={bot.widget} />
                    </div>
                </div>

                <BotChatPreview bot={bot} currentTeamSlug={currentTeam.slug} />
            </div>

            <DeleteBotDialog
                bot={bot}
                currentTeamSlug={currentTeam.slug}
                open={deleteDialogOpen}
                onOpenChange={setDeleteDialogOpen}
            />
        </>
    );
}
