import { Head, Link, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    CheckCircle2,
    CircleAlert,
    Inbox,
    MessageSquare,
    Plus,
    Sparkles,
    Users,
    Zap,
} from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import PendingInvitationsModal from '@/components/pending-invitations-modal';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { dashboard } from '@/routes';
import { index as notificationsIndex } from '@/routes/notifications';
import type { DashboardProps, DashboardRange } from '@/types';

const ranges: Array<{ value: DashboardRange; label: string }> = [
    { value: 'today', label: 'today' },
    { value: '7d', label: 'last_7_days' },
    { value: '30d', label: 'last_30_days' },
];

function number(value: number): string {
    return new Intl.NumberFormat().format(value);
}

function relativeDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    const seconds = Math.max(
        0,
        Math.round((Date.now() - new Date(value).getTime()) / 1000),
    );

    if (seconds < 60) {
        return `${seconds}s`;
    }

    if (seconds < 3600) {
        return `${Math.round(seconds / 60)}m`;
    }

    if (seconds < 86400) {
        return `${Math.round(seconds / 3600)}h`;
    }

    return `${Math.round(seconds / 86400)}d`;
}

function stateIcon(state: 'healthy' | 'warning' | 'error') {
    return state === 'healthy'
        ? CheckCircle2
        : state === 'warning'
          ? AlertTriangle
          : CircleAlert;
}

function LineChart({ points }: { points: DashboardProps['activity'] }) {
    const { t } = useTranslation();

    if (points.every((point) => point.value === 0)) {
        return (
            <p className="py-16 text-center text-sm text-muted-foreground">
                {t('common.no_items')}
            </p>
        );
    }

    const width = 720;
    const height = 220;
    const padding = 18;
    const max = Math.max(...points.map((point) => point.value), 1);
    const step =
        points.length > 1 ? (width - padding * 2) / (points.length - 1) : 0;
    const coordinates = points.map(
        (point, index) =>
            `${padding + step * index},${height - padding - (point.value / max) * (height - padding * 2)}`,
    );

    return (
        <div className="overflow-x-auto">
            <svg
                viewBox={`0 0 ${width} ${height}`}
                className="h-56 w-full min-w-[36rem]"
                role="img"
                aria-label={t('dashboard.conversation_activity')}
            >
                {[0, 1, 2, 3].map((line) => {
                    const y = padding + (line * (height - padding * 2)) / 3;

                    return (
                        <line
                            key={line}
                            x1={padding}
                            x2={width - padding}
                            y1={y}
                            y2={y}
                            className="stroke-border"
                        />
                    );
                })}
                <polyline
                    points={coordinates.join(' ')}
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="3"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    className="text-primary"
                />
                {points.map((point, index) => {
                    const [x, y] = coordinates[index].split(',');

                    return (
                        <circle
                            key={point.date}
                            cx={x}
                            cy={y}
                            r="4"
                            className="fill-background stroke-primary"
                            strokeWidth="2"
                        />
                    );
                })}
            </svg>
            <div className="flex justify-between text-xs text-muted-foreground">
                <span>{points[0]?.date}</span>
                <span>{points.at(-1)?.date}</span>
            </div>
        </div>
    );
}

export default function Dashboard({
    pendingInvitations = [],
    ...props
}: DashboardProps) {
    const { t } = useTranslation();
    const { currentTeam, currentTeamPermissions } = usePage().props;
    const [showInvitations, setShowInvitations] = useState(
        pendingInvitations.length > 0,
    );
    const hour = new Date().getHours();
    const period = hour < 12 ? 'morning' : hour < 18 ? 'afternoon' : 'evening';
    const metricCards = [
        ['conversations', MessageSquare, props.metrics.conversations],
        ['leads', Users, props.metrics.leads],
        ['successful_actions', Zap, props.metrics.successfulActions],
        ['handoffs', Inbox, props.metrics.handoffs],
    ] as const;

    if (!currentTeam) {
        return null;
    }

    const rangeUrl = (range: DashboardRange) =>
        dashboard(currentTeam.slug, { query: { range } }).url;
    const canBilling = currentTeamPermissions?.['billing.view'] === true;

    return (
        <>
            <Head title={t('navigation.dashboard')} />
            <PendingInvitationsModal
                invitations={pendingInvitations}
                open={pendingInvitations.length > 0 && showInvitations}
                onOpenChange={setShowInvitations}
            />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <section className="flex flex-col justify-between gap-4 rounded-xl border bg-card p-5 shadow-sm sm:flex-row sm:items-end">
                    <div>
                        <p className="text-sm text-muted-foreground">
                            {t(`dashboard.good_${period}`)}
                        </p>
                        <h1 className="mt-1 text-2xl font-semibold tracking-tight">
                            {props.team.name}
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {t('dashboard.attention_description')}
                        </p>
                    </div>
                    <div className="flex gap-1 rounded-lg border p-1">
                        {ranges.map((range) => (
                            <Link
                                key={range.value}
                                href={rangeUrl(range.value)}
                                className={`rounded-md px-3 py-1.5 text-sm ${props.range === range.value ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted'}`}
                            >
                                {t(`common.${range.label}`)}
                            </Link>
                        ))}
                    </div>
                </section>

                {props.setup.isSetup ? (
                    <Card className="border-primary/20 bg-primary/5">
                        <CardHeader>
                            <CardTitle>{t('dashboard.setup_title')}</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            {props.setup.steps.slice(0, 4).map((step) => (
                                <Link
                                    key={step.key}
                                    href={step.actionUrl}
                                    className="flex items-center gap-3 rounded-lg border bg-background p-3 transition-colors hover:border-primary"
                                >
                                    <span
                                        className={`flex size-7 items-center justify-center rounded-full ${step.completed ? 'bg-primary text-primary-foreground' : 'bg-muted'}`}
                                    >
                                        {step.completed ? (
                                            <CheckCircle2 className="size-4" />
                                        ) : (
                                            <ArrowRight className="size-4" />
                                        )}
                                    </span>
                                    <span className="text-sm font-medium">
                                        {t(
                                            `dashboard.steps.${step.key}`,
                                            step.label,
                                        )}
                                    </span>
                                </Link>
                            ))}
                        </CardContent>
                    </Card>
                ) : null}

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {metricCards.map(([key, Icon, metric]) => (
                        <Link key={key} href={metric.url} className="group">
                            <Card className="h-full transition-colors group-hover:border-primary">
                                <CardContent className="p-5">
                                    <div className="flex items-center justify-between">
                                        <span className="text-sm text-muted-foreground">
                                            {t(`dashboard.metrics.${key}`)}
                                        </span>
                                        <Icon className="size-4 text-muted-foreground" />
                                    </div>
                                    <p className="mt-3 text-3xl font-semibold">
                                        {number(metric.value)}
                                    </p>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {metric.change === null
                                            ? t('dashboard.no_comparison')
                                            : `${metric.change > 0 ? '+' : ''}${metric.change}% ${t('dashboard.vs_previous')}`}
                                    </p>
                                </CardContent>
                            </Card>
                        </Link>
                    ))}
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <CircleAlert className="size-4" />
                                {t('dashboard.needs_attention')}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {props.attention.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    {t('dashboard.everything_good')}
                                </p>
                            ) : (
                                props.attention.map((item) => (
                                    <Link
                                        key={item.key}
                                        href={item.url}
                                        className="flex items-center justify-between rounded-lg border p-3 text-sm hover:bg-muted"
                                    >
                                        <span>
                                            {t(
                                                `dashboard.attention.${item.key}`,
                                            )}
                                        </span>
                                        <Badge variant="secondary">
                                            {number(item.count)}
                                        </Badge>
                                    </Link>
                                ))
                            )}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <CheckCircle2 className="size-4" />
                                {t('dashboard.system_health')}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-2 sm:grid-cols-2">
                            {(
                                [
                                    'bots',
                                    'data',
                                    'integrations',
                                    'channels',
                                ] as const
                            ).map((key) => {
                                const item = props.health[key];
                                const Icon = stateIcon(item.state);

                                return (
                                    <div
                                        key={key}
                                        className="flex items-center justify-between rounded-lg border p-3 text-sm"
                                    >
                                        <span className="flex items-center gap-2">
                                            <Icon className="size-4" />
                                            {t(`dashboard.health.${key}`)}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {t(
                                                `status.${item.state}`,
                                                item.state,
                                            )}
                                        </span>
                                    </div>
                                );
                            })}
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>
                            {t('dashboard.conversation_activity')}
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <LineChart points={props.activity} />
                    </CardContent>
                </Card>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {t('dashboard.recent_conversations')}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {props.recentConversations.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    {t('dashboard.no_conversations')}
                                </p>
                            ) : (
                                props.recentConversations.map(
                                    (conversation) => (
                                        <Link
                                            key={conversation.reference}
                                            href={conversation.url}
                                            className="flex items-center gap-3 rounded-lg border p-3 hover:bg-muted"
                                        >
                                            <MessageSquare className="size-4 text-muted-foreground" />
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate text-sm font-medium">
                                                    {conversation.title}
                                                </span>
                                                <span className="block text-xs text-muted-foreground">
                                                    {t(
                                                        `channels.${conversation.channel}`,
                                                        conversation.channel,
                                                    )}{' '}
                                                    ·{' '}
                                                    {t(
                                                        `status.${conversation.status}`,
                                                        conversation.status,
                                                    )}
                                                    {conversation.assignee
                                                        ? ` · ${conversation.assignee}`
                                                        : ''}
                                                </span>
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                {relativeDate(
                                                    conversation.lastActivityAt,
                                                )}
                                            </span>
                                        </Link>
                                    ),
                                )
                            )}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Sparkles className="size-4" />
                                {t('dashboard.ai_improvements')}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {props.improvements.opportunities.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    {t('dashboard.no_improvements')}
                                </p>
                            ) : (
                                props.improvements.opportunities
                                    .slice(0, 5)
                                    .map((opportunity, index) => (
                                        <div
                                            key={`${opportunity.type}-${index}`}
                                            className="flex gap-3 rounded-lg border p-3"
                                        >
                                            <Sparkles className="mt-0.5 size-4 shrink-0 text-primary" />
                                            <span className="text-sm">
                                                {opportunity.title ??
                                                    t(
                                                        'dashboard.improvement_opportunity',
                                                    )}
                                            </span>
                                        </div>
                                    ))
                            )}
                            <Button variant="link" className="px-0" asChild>
                                <Link href={props.improvements.url}>
                                    {t('dashboard.view_improvements')}
                                    <ArrowRight />
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                </div>

                <div className="grid gap-4 lg:grid-cols-3">
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {t('dashboard.business_outcomes')}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2 text-sm">
                            {[
                                ['leads', props.outcomes.leads],
                                ['appointments', props.outcomes.appointments],
                                ['tickets', props.outcomes.tickets],
                                [
                                    'completed_actions',
                                    props.outcomes.completedActions,
                                ],
                            ].map(([key, value]) => (
                                <div key={key} className="flex justify-between">
                                    <span className="text-muted-foreground">
                                        {t(`dashboard.outcomes.${key}`)}
                                    </span>
                                    <span className="font-medium">
                                        {number(value as number)}
                                    </span>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {t('dashboard.workspace_summary')}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2 text-sm">
                            <Link
                                href={props.bots.url}
                                className="flex justify-between hover:text-primary"
                            >
                                <span>{t('navigation.bots')}</span>
                                <span>
                                    {number(props.bots.ready)} /{' '}
                                    {number(props.bots.total)}
                                </span>
                            </Link>
                            <Link
                                href={props.channels.url}
                                className="flex justify-between hover:text-primary"
                            >
                                <span>{t('navigation.channels')}</span>
                                <span>
                                    {number(props.channels.active)} /{' '}
                                    {number(props.channels.total)}
                                </span>
                            </Link>
                            {props.unreadNotifications > 0 ? (
                                <Link
                                    href={
                                        notificationsIndex(currentTeam.slug).url
                                    }
                                    className="flex justify-between hover:text-primary"
                                >
                                    <span>{t('dashboard.notifications')}</span>
                                    <span>
                                        {number(props.unreadNotifications)}
                                    </span>
                                </Link>
                            ) : null}
                        </CardContent>
                    </Card>
                    {canBilling && props.billing ? (
                        <Card>
                            <CardHeader>
                                <CardTitle>
                                    {t('dashboard.billing_usage')}
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-2 text-sm">
                                <p className="font-medium">
                                    {props.billing.plan.name}
                                </p>
                                {Object.entries(props.billing.usage)
                                    .filter(([key]) =>
                                        [
                                            'monthly_conversations',
                                            'monthly_actions',
                                        ].includes(key),
                                    )
                                    .map(([key, usage]) => (
                                        <div
                                            key={key}
                                            className="flex justify-between"
                                        >
                                            <span className="text-muted-foreground">
                                                {t(`billing.${key}`)}
                                            </span>
                                            <span>
                                                {number(usage.used)}
                                                {usage.limit === null
                                                    ? ''
                                                    : ` / ${number(usage.limit)}`}
                                            </span>
                                        </div>
                                    ))}
                            </CardContent>
                        </Card>
                    ) : (
                        <Card>
                            <CardHeader>
                                <CardTitle>
                                    {t('dashboard.quick_actions')}
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-2">
                                {props.quickActions
                                    .slice(0, 5)
                                    .map((action) => (
                                        <Link
                                            key={action.key}
                                            href={action.url}
                                            className="flex items-center gap-2 text-sm hover:text-primary"
                                        >
                                            <Plus className="size-4" />
                                            {t(
                                                `dashboard.actions.${action.key}`,
                                                action.key,
                                            )}
                                        </Link>
                                    ))}
                            </CardContent>
                        </Card>
                    )}
                </div>
            </div>
        </>
    );
}

Dashboard.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: props.currentTeam ? dashboard(props.currentTeam.slug) : '/',
        },
    ],
});
