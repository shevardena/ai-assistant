import { Head, Link, usePage } from '@inertiajs/react';
import { Bell, Check, ExternalLink } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import {
    index as notificationsIndex,
    read as notificationRead,
    readAll as notificationsReadAll,
    unread as notificationUnread,
} from '@/routes/notifications';
import type { NotificationPageProps } from '@/types';

function formatDate(value: string | null): string {
    return value
        ? new Intl.DateTimeFormat(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(new Date(value))
        : '—';
}

export default function NotificationsIndex({
    filter,
    totalCount,
    unreadCount,
    notifications,
}: NotificationPageProps) {
    const { t } = useTranslation();
    const { currentTeam } = usePage().props;

    if (!currentTeam) {
        return null;
    }

    const team = currentTeam;

    return (
        <>
            <Head title={t('navigation.notifications')} />

            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                    <div>
                        <div className="flex items-center gap-2">
                            <Bell className="size-5 text-muted-foreground" />
                            <h1 className="text-xl font-semibold tracking-tight">
                                {t('navigation.notifications')}
                            </h1>
                        </div>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {t('common.activity_description')}
                        </p>
                    </div>

                    {unreadCount > 0 ? (
                        <Link
                            href={notificationsReadAll(team.slug).url}
                            method="post"
                            as="button"
                            className="inline-flex h-9 items-center justify-center gap-2 rounded-md border px-3 text-sm font-medium transition-colors hover:bg-accent"
                        >
                            <Check className="size-4" />
                            {t('common.mark_all_read')}
                        </Link>
                    ) : null}
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Card className="gap-2 py-4">
                        <CardContent className="px-4">
                            <p className="text-sm text-muted-foreground">
                                {t('common.unread')}
                            </p>
                            <p className="mt-1 text-2xl font-semibold">
                                {unreadCount}
                            </p>
                        </CardContent>
                    </Card>
                    <Card className="gap-2 py-4">
                        <CardContent className="px-4">
                            <p className="text-sm text-muted-foreground">
                                {t('common.total')}
                            </p>
                            <p className="mt-1 text-2xl font-semibold">
                                {totalCount}
                            </p>
                        </CardContent>
                    </Card>
                </div>

                <div className="flex items-center gap-1 rounded-lg border p-1 sm:w-fit">
                    {(['all', 'unread'] as const).map((value) => (
                        <Link
                            key={value}
                            href={
                                notificationsIndex(team.slug, {
                                    query: { filter: value },
                                }).url
                            }
                            className={`rounded-md px-3 py-1.5 text-sm font-medium transition-colors ${
                                filter === value
                                    ? 'bg-accent text-accent-foreground'
                                    : 'text-muted-foreground hover:text-foreground'
                            }`}
                            aria-current={filter === value ? 'page' : undefined}
                        >
                            {value === 'all'
                                ? t('common.all_notifications')
                                : t('common.unread')}
                        </Link>
                    ))}
                </div>

                {notifications.data.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center gap-2 px-6 py-12 text-center">
                            <Bell className="size-8 text-muted-foreground" />
                            <h2 className="font-medium">
                                {filter === 'unread'
                                    ? t('common.caught_up')
                                    : t('common.no_notifications')}
                            </h2>
                            <p className="max-w-md text-sm text-muted-foreground">
                                {t('common.activity_description')}
                            </p>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="overflow-hidden rounded-xl border">
                        <div className="divide-y">
                            {notifications.data.map((notification) => {
                                const unread = notification.readAt === null;

                                return (
                                    <div
                                        key={notification.id}
                                        className={`flex flex-col gap-3 p-4 sm:flex-row sm:items-start sm:justify-between ${unread ? 'bg-accent/30' : ''}`}
                                    >
                                        <div className="flex min-w-0 gap-3">
                                            <span
                                                className={`mt-1.5 size-2 shrink-0 rounded-full ${unread ? 'bg-primary' : 'bg-transparent'}`}
                                                aria-hidden="true"
                                            />
                                            <div className="min-w-0">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <h2 className="font-medium">
                                                        {notification.title}
                                                    </h2>
                                                    {unread ? (
                                                        <Badge variant="secondary">
                                                            {t('common.unread')}
                                                        </Badge>
                                                    ) : null}
                                                </div>
                                                {notification.botName ? (
                                                    <p className="mt-1 text-xs font-medium text-muted-foreground">
                                                        {notification.botName}
                                                    </p>
                                                ) : null}
                                                <p className="mt-1 text-sm text-muted-foreground">
                                                    {notification.message}
                                                </p>
                                                <p className="mt-2 text-xs text-muted-foreground">
                                                    {formatDate(
                                                        notification.createdAt,
                                                    )}
                                                </p>
                                            </div>
                                        </div>

                                        <div className="flex shrink-0 items-center gap-2 sm:pt-0.5">
                                            {notification.href ? (
                                                <Link
                                                    href={notification.href}
                                                    className="inline-flex items-center gap-1 text-sm font-medium hover:underline"
                                                >
                                                    {t('common.view')}
                                                    <ExternalLink className="size-3.5" />
                                                </Link>
                                            ) : null}
                                            <Link
                                                href={
                                                    unread
                                                        ? notificationRead([
                                                              team.slug,
                                                              notification.id,
                                                          ]).url
                                                        : notificationUnread([
                                                              team.slug,
                                                              notification.id,
                                                          ]).url
                                                }
                                                method="patch"
                                                as="button"
                                                className="inline-flex items-center rounded-md border px-2.5 py-1.5 text-xs font-medium hover:bg-accent"
                                            >
                                                {unread
                                                    ? t('common.mark_read')
                                                    : t('common.mark_unread')}
                                            </Link>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                )}

                {notifications.last_page > 1 ? (
                    <div className="flex flex-wrap gap-2">
                        {notifications.links.map((link) =>
                            link.url ? (
                                <Link
                                    key={link.label}
                                    href={link.url}
                                    className={`rounded-md border px-3 py-1.5 text-sm ${link.active ? 'bg-accent font-medium' : 'hover:bg-accent'}`}
                                    dangerouslySetInnerHTML={{
                                        __html: link.label,
                                    }}
                                />
                            ) : null,
                        )}
                    </div>
                ) : null}
            </div>
        </>
    );
}
