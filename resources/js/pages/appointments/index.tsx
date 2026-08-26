import { Head, Link, router, usePage } from '@inertiajs/react';
import { CalendarDays } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    index as appointmentsIndex,
    show as appointmentsShow,
} from '@/routes/appointments';
import type {
    AppointmentFilters,
    AppointmentPageProps,
    AppointmentRange,
    AppointmentStatus,
    Paginated,
} from '@/types';

const ranges: Array<{ value: AppointmentRange; label: string }> = [
    { value: 'today', label: 'common.today' },
    { value: '7d', label: 'common.last_7_days' },
    { value: '30d', label: 'common.last_30_days' },
    { value: '90d', label: 'common.last_90_days' },
    { value: 'all', label: 'common.all_time' },
];
const statuses: Array<{ value: AppointmentStatus | 'all'; label: string }> = [
    { value: 'all', label: 'common.all_statuses' },
    { value: 'scheduled', label: 'common.scheduled' },
    { value: 'completed', label: 'status.completed' },
    { value: 'no_show', label: 'common.no_show' },
    { value: 'cancelled', label: 'status.cancelled' },
];

function date(value: string | null): string {
    return value
        ? new Intl.DateTimeFormat(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(new Date(value))
        : '—';
}
function variant(
    status: AppointmentStatus,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    return status === 'completed'
        ? 'default'
        : status === 'cancelled' || status === 'no_show'
          ? 'destructive'
          : status === 'scheduled'
            ? 'secondary'
            : 'outline';
}
function pagination<T>(page: Paginated<T>, translate: (key: string) => string) {
    return page.last_page <= 1 ? null : (
        <nav
            className="flex flex-wrap justify-center gap-2"
            aria-label={`${translate('navigation.appointments')} ${translate('common.pagination')}`}
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
                                .replace(
                                    '&laquo;',
                                    translate('common.previous_page'),
                                )
                                .replace(
                                    '&raquo;',
                                    translate('common.next_page'),
                                )}
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
                            .replace(
                                '&laquo;',
                                translate('common.previous_page'),
                            )
                            .replace('&raquo;', translate('common.next_page'))}
                    </Button>
                ),
            )}
        </nav>
    );
}

export default function AppointmentsIndex({
    filters,
    botOptions,
    summary,
    appointments,
}: AppointmentPageProps) {
    const { t } = useTranslation();
    const { currentTeam } = usePage().props;

    if (!currentTeam) {
        return null;
    }

    const visit = (changes: Partial<AppointmentFilters>) => {
        const next = { ...filters, ...changes };
        router.get(appointmentsIndex(currentTeam.slug, { query: next }).url);
    };
    const cards = [
        [t('common.upcoming'), summary.upcoming],
        [t('common.today'), summary.today],
        [t('status.completed'), summary.completed],
        [t('common.no_show'), summary.noShow],
        [t('status.cancelled'), summary.cancelled],
    ];

    return (
        <>
            <Head title={t('navigation.appointments')} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <Heading
                    variant="small"
                    title={t('navigation.appointments')}
                    description={t('common.review_appointments')}
                />
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    {cards.map(([label, value]) => (
                        <Card key={label}>
                            <CardContent className="p-4">
                                <p className="text-sm text-muted-foreground">
                                    {label}
                                </p>
                                <p className="mt-2 text-3xl font-semibold tracking-tight">
                                    {value}
                                </p>
                            </CardContent>
                        </Card>
                    ))}
                </div>
                <div className="flex flex-col gap-3 rounded-xl border p-3">
                    <div className="flex flex-wrap gap-1 rounded-lg border p-1">
                        {ranges.map((range) => (
                            <Link
                                key={range.value}
                                href={
                                    appointmentsIndex(currentTeam.slug, {
                                        query: {
                                            ...filters,
                                            range: range.value,
                                        },
                                    }).url
                                }
                                className={`rounded-md px-3 py-1.5 text-sm ${filters.range === range.value ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted hover:text-foreground'}`}
                            >
                                {t(range.label)}
                            </Link>
                        ))}
                    </div>
                    <div className="flex flex-col gap-3 lg:flex-row">
                        <select
                            aria-label={t('common.filter_by_bot')}
                            value={filters.bot ?? ''}
                            onChange={(event) =>
                                visit({ bot: event.target.value || null })
                            }
                            className="rounded-lg border bg-transparent px-3 py-2 text-sm"
                        >
                            <option value="">{t('common.all_bots')}</option>
                            {botOptions.map((bot) => (
                                <option key={bot.slug} value={bot.slug}>
                                    {bot.name}
                                </option>
                            ))}
                        </select>
                        <select
                            aria-label={t('common.filter_by_status')}
                            value={filters.status}
                            onChange={(event) =>
                                visit({
                                    status: event.target.value as
                                        AppointmentStatus | 'all',
                                })
                            }
                            className="rounded-lg border bg-transparent px-3 py-2 text-sm"
                        >
                            {statuses.map((status) => (
                                <option key={status.value} value={status.value}>
                                    {t(status.label)}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>
                {appointments.data.length === 0 ? (
                    <div className="rounded-xl border border-dashed p-10 text-center">
                        <CalendarDays className="mx-auto size-8 text-muted-foreground" />
                        <p className="mt-3 font-medium">
                            {t('common.no_appointments')}
                        </p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {t('common.confirmed_bookings_empty')}
                        </p>
                    </div>
                ) : (
                    <>
                        <div className="overflow-hidden rounded-xl border">
                            <div className="hidden grid-cols-[minmax(14rem,1.5fr)_minmax(10rem,1fr)_10rem_12rem] gap-4 border-b bg-muted/30 px-4 py-3 text-sm text-muted-foreground md:grid">
                                <span>{t('common.appointment')}</span>
                                <span>{t('common.bot')}</span>
                                <span>{t('common.status')}</span>
                                <span>{t('common.starts')}</span>
                            </div>
                            <div className="divide-y">
                                {appointments.data.map((appointment) => (
                                    <Link
                                        key={appointment.reference}
                                        href={
                                            appointmentsShow([
                                                currentTeam.slug,
                                                appointment.reference,
                                            ]).url
                                        }
                                        className="grid gap-2 px-4 py-4 hover:bg-muted/30 md:grid-cols-[minmax(14rem,1.5fr)_minmax(10rem,1fr)_10rem_12rem] md:items-center md:gap-4"
                                    >
                                        <div>
                                            <p className="font-medium">
                                                {appointment.customerName ??
                                                    appointment.customerEmail ??
                                                    t('common.appointment')}
                                            </p>
                                            <p className="text-sm text-muted-foreground">
                                                {appointment.customerEmail ??
                                                    t('common.no_email')}
                                            </p>
                                        </div>
                                        <span className="text-sm">
                                            {appointment.bot?.name ?? '—'}
                                        </span>
                                        <span>
                                            <Badge
                                                variant={variant(
                                                    appointment.status,
                                                )}
                                            >
                                                {appointment.statusLabel}
                                            </Badge>
                                        </span>
                                        <span className="text-sm">
                                            {date(appointment.startsAt)}
                                        </span>
                                    </Link>
                                ))}
                            </div>
                        </div>
                        {pagination(appointments, t)}
                    </>
                )}
            </div>
        </>
    );
}
