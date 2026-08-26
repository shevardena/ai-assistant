import { Head, Link, usePage } from '@inertiajs/react';
import {
    Bot as BotIcon,
    Eye,
    Pencil,
    Plus,
    Sparkles,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import DeleteBotDialog from '@/components/delete-bot-dialog';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { botStatusVariant, statusLabel } from '@/lib/status';
import { create, edit, show } from '@/routes/bots';
import { index as onboardingIndex } from '@/routes/onboarding';
import type { BotSummary, Paginated } from '@/types';

type Props = {
    bots: Paginated<BotSummary>;
};

function formatDate(value: string | null): string {
    return value
        ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(
              new Date(value),
          )
        : '—';
}

function paginationLabel(
    label: string,
    translate: (key: string) => string,
): string {
    return label
        .replace('&laquo;', translate('common.previous_page'))
        .replace('&raquo;', translate('common.next_page'));
}

export default function BotsIndex({ bots }: Props) {
    const { t } = useTranslation();
    const { currentTeam } = usePage().props;
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [botToDelete, setBotToDelete] = useState<BotSummary | null>(null);

    if (!currentTeam) {
        return null;
    }

    const openDeleteDialog = (bot: BotSummary) => {
        setBotToDelete(bot);
        setDeleteDialogOpen(true);
    };

    return (
        <>
            <Head title={t('navigation.bots')} />

            <h1 className="sr-only">{t('navigation.bots')}</h1>

            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                    <Heading
                        variant="small"
                        title={t('navigation.bots')}
                        description={t('common.manage_team_bots')}
                    />

                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" asChild>
                            <Link href={onboardingIndex(currentTeam.slug).url}>
                                <Sparkles />
                                {t('common.use_template')}
                            </Link>
                        </Button>
                        <Button asChild data-test="bots-create-button">
                            <Link href={create(currentTeam.slug).url}>
                                <Plus />
                                {t('common.create_bot')}
                            </Link>
                        </Button>
                    </div>
                </div>

                {bots.data.length > 0 ? (
                    <div className="overflow-hidden rounded-xl border">
                        <div className="hidden grid-cols-[minmax(0,1fr)_8rem_10rem_7rem] gap-4 border-b bg-muted/40 px-4 py-3 text-sm font-medium text-muted-foreground md:grid">
                            <span>{t('common.bot')}</span>
                            <span>{t('common.status')}</span>
                            <span>{t('common.updated')}</span>
                            <span className="text-right">
                                {t('common.actions')}
                            </span>
                        </div>

                        <div className="divide-y">
                            {bots.data.map((bot) => (
                                <div
                                    key={bot.id}
                                    className="grid gap-4 px-4 py-4 md:grid-cols-[minmax(0,1fr)_8rem_10rem_7rem] md:items-center"
                                    data-test="bot-row"
                                >
                                    <div className="flex min-w-0 items-center gap-3">
                                        <div className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                            <BotIcon className="size-5" />
                                        </div>
                                        <div className="min-w-0">
                                            <Link
                                                href={
                                                    show([
                                                        currentTeam.slug,
                                                        bot.id,
                                                    ]).url
                                                }
                                                className="truncate font-medium hover:underline"
                                            >
                                                {bot.name}
                                            </Link>
                                            <p className="truncate text-sm text-muted-foreground">
                                                {bot.slug}
                                            </p>
                                        </div>
                                    </div>

                                    <div>
                                        <Badge
                                            variant={botStatusVariant(
                                                bot.status,
                                            )}
                                        >
                                            {statusLabel(bot.status)}
                                        </Badge>
                                    </div>

                                    <p className="text-sm text-muted-foreground">
                                        {formatDate(bot.updatedAt)}
                                    </p>

                                    <div className="flex items-center justify-start gap-1 md:justify-end">
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            asChild
                                        >
                                            <Link
                                                href={
                                                    show([
                                                        currentTeam.slug,
                                                        bot.id,
                                                    ]).url
                                                }
                                                aria-label={`${t('common.view')} ${bot.name}`}
                                            >
                                                <Eye />
                                            </Link>
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            asChild
                                        >
                                            <Link
                                                href={
                                                    edit([
                                                        currentTeam.slug,
                                                        bot.id,
                                                    ]).url
                                                }
                                                aria-label={`${t('common.edit')} ${bot.name}`}
                                            >
                                                <Pencil />
                                            </Link>
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            aria-label={`${t('common.delete')} ${bot.name}`}
                                            data-test="bot-delete-button"
                                            onClick={() =>
                                                openDeleteDialog(bot)
                                            }
                                        >
                                            <Trash2 />
                                        </Button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                ) : (
                    <div className="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed px-6 py-16 text-center">
                        <div className="flex size-12 items-center justify-center rounded-full bg-primary/10 text-primary">
                            <BotIcon className="size-6" />
                        </div>
                        <div className="space-y-1">
                            <h2 className="font-medium">
                                {t('common.no_bots')}
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                Create your first bot to start building your
                                assistant.
                            </p>
                        </div>
                        <Button asChild>
                            <Link href={create(currentTeam.slug).url}>
                                <Plus />
                                {t('common.create_bot')}
                            </Link>
                        </Button>
                    </div>
                )}

                {bots.last_page > 1 ? (
                    <nav
                        className="flex flex-wrap items-center justify-center gap-2"
                        aria-label={`${t('navigation.bots')} ${t('common.pagination')}`}
                    >
                        {bots.links.map((link, index) =>
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
                                        {paginationLabel(link.label, t)}
                                    </Link>
                                </Button>
                            ) : (
                                <Button
                                    key={`${link.label}-${index}`}
                                    variant="outline"
                                    size="sm"
                                    disabled
                                >
                                    {paginationLabel(link.label, t)}
                                </Button>
                            ),
                        )}
                    </nav>
                ) : null}
            </div>

            <DeleteBotDialog
                bot={botToDelete}
                currentTeamSlug={currentTeam.slug}
                open={deleteDialogOpen}
                onOpenChange={setDeleteDialogOpen}
            />
        </>
    );
}
