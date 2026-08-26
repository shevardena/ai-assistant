import { Head, useForm, usePage } from '@inertiajs/react';
import { Check, CreditCard } from 'lucide-react';
import type { FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cancel, portal, resume } from '@/routes/billing';
import { update as updatePlan } from '@/routes/billing/plan';
import type {
    BillingPageProps,
    BillingUsageMetric,
    PlanDefinition,
    PlanLimit,
} from '@/types';

const limitKeys: Array<{ key: PlanLimit; label: string }> = [
    { key: 'bots', label: 'common.bot' },
    { key: 'team_members', label: 'billing.team_members' },
    { key: 'monthly_conversations', label: 'billing.customer_conversations' },
    { key: 'monthly_actions', label: 'billing.production_actions' },
];

const featureKeys = [
    'analytics',
    'human_handoff',
    'workflows',
    'bot_testing',
    'advanced_health',
    'business_templates',
    'notifications',
    'voice_input',
] as const;

function number(value: number): string {
    return new Intl.NumberFormat().format(value);
}

function limitLabel(
    metric: BillingUsageMetric,
    unlimitedLabel: string,
): string {
    return metric.unlimited
        ? `${number(metric.used)} / ${unlimitedLabel}`
        : `${number(metric.used)} / ${number(metric.limit ?? 0)}`;
}

function featureLabel(feature: string): string {
    if (feature === 'voice_input') {
        return 'Voice input (speech-to-text)';
    }

    return feature
        .split('_')
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
        .join(' ');
}

function PlanCard({
    plan,
    currentPlan,
    canManage,
    onChoose,
    processing,
}: {
    plan: PlanDefinition;
    currentPlan: string;
    canManage: boolean;
    onChoose: (planKey: string) => void;
    processing: boolean;
}) {
    const { t } = useTranslation();

    return (
        <Card>
            <CardHeader>
                <CardTitle>{plan.name}</CardTitle>
                <p className="text-sm text-muted-foreground">
                    {plan.description}
                </p>
                <p className="pt-2 text-sm font-medium text-muted-foreground">
                    {plan.stripe_configured
                        ? t('billing.stripe_available')
                        : t('billing.billing_unavailable')}
                </p>
            </CardHeader>
            <CardContent className="space-y-4">
                <div className="space-y-3 text-sm">
                    {limitKeys.map(({ key, label }) => {
                        const limit = plan.limits[key];

                        return (
                            <div
                                key={key}
                                className="flex items-center justify-between gap-3"
                            >
                                <span>{t(label)}</span>
                                <span className="font-medium">
                                    {limit.value === null
                                        ? t('billing.unlimited')
                                        : number(limit.value)}
                                </span>
                            </div>
                        );
                    })}
                </div>
                <div className="space-y-2 border-t pt-4">
                    {featureKeys.map((feature) => (
                        <div
                            key={feature}
                            className="flex items-center gap-2 text-sm"
                        >
                            <Check className="size-4 text-primary" />
                            <span
                                className={
                                    !plan.features[feature]
                                        ? 'text-muted-foreground line-through'
                                        : ''
                                }
                            >
                                {featureLabel(feature)}
                            </span>
                        </div>
                    ))}
                </div>
                {canManage &&
                plan.stripe_configured &&
                plan.key !== currentPlan ? (
                    <Button
                        type="button"
                        className="w-full"
                        disabled={processing}
                        onClick={() => onChoose(plan.key)}
                    >
                        {currentPlan === 'free' || currentPlan === 'legacy'
                            ? t('billing.choose_plan')
                            : t('billing.change_plan')}
                    </Button>
                ) : plan.key === currentPlan ? (
                    <Badge variant="outline">{t('billing.current_plan')}</Badge>
                ) : null}
            </CardContent>
        </Card>
    );
}

export default function Billing({
    summary,
    plans,
    subscription,
}: BillingPageProps) {
    const { t } = useTranslation();
    const { currentTeam, currentTeamPermissions } = usePage().props;
    const planForm = useForm<{ plan_key: string }>({ plan_key: '' });
    const portalForm = useForm();
    const cancellationForm = useForm();

    if (!currentTeam) {
        return null;
    }

    const currentTeamSlug = currentTeam.slug;
    const canManage = currentTeamPermissions?.['billing.manage'] === true;
    const metrics = limitKeys.map(({ key }) => summary.usage[key]);

    function choosePlan(planKey: string): void {
        planForm.setData('plan_key', planKey);
        planForm.post(updatePlan(currentTeamSlug).url, {
            preserveScroll: true,
        });
    }

    function openPortal(event: FormEvent<HTMLFormElement>): void {
        event.preventDefault();
        portalForm.post(portal(currentTeamSlug).url);
    }

    function toggleCancellation(event: FormEvent<HTMLFormElement>): void {
        event.preventDefault();
        const action = subscription.cancel_at_period_end
            ? resume(currentTeamSlug)
            : cancel(currentTeamSlug);
        cancellationForm.post(action.url, { preserveScroll: true });
    }

    const periodEnd = subscription.current_period_end
        ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(
              new Date(subscription.current_period_end),
          )
        : null;

    return (
        <>
            <Head title={t('billing.title')} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
                    <Heading
                        variant="small"
                        title={t('billing.title')}
                        description={t('billing.description')}
                    />
                    <div className="flex items-center gap-2 text-sm text-muted-foreground">
                        <CreditCard className="size-4" />
                        {t('billing.provider_payments')}
                    </div>
                </div>
                <Card>
                    <CardHeader className="flex flex-row items-start justify-between gap-4">
                        <div>
                            <CardTitle>{t('billing.current_plan')}</CardTitle>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {summary.plan.description}
                            </p>
                        </div>
                        <Badge variant="secondary">
                            {summary.status.replace('_', ' ')}
                        </Badge>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <p className="text-3xl font-semibold tracking-tight">
                                {summary.plan.name}
                            </p>
                            <p className="text-sm text-muted-foreground">
                                {t('billing.usage_label', {
                                    period: summary.period.label,
                                })}
                            </p>
                            {periodEnd ? (
                                <p className="text-sm text-muted-foreground">
                                    {subscription.cancel_at_period_end
                                        ? `${t('billing.cancels_on')} ${periodEnd}`
                                        : `${t('billing.renews_on')} ${periodEnd}`}
                                </p>
                            ) : null}
                        </div>
                        {canManage &&
                        subscription.provider === 'stripe' &&
                        subscription.has_billing_customer ? (
                            <div className="flex flex-wrap gap-2">
                                <form onSubmit={openPortal}>
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={portalForm.processing}
                                    >
                                        {t('billing.manage_billing')}
                                    </Button>
                                </form>
                                {summary.plan.key !== 'free' &&
                                summary.plan.key !== 'legacy' ? (
                                    <form onSubmit={toggleCancellation}>
                                        <Button
                                            type="submit"
                                            variant="ghost"
                                            disabled={
                                                cancellationForm.processing
                                            }
                                        >
                                            {subscription.cancel_at_period_end
                                                ? t(
                                                      'billing.resume_subscription',
                                                  )
                                                : t(
                                                      'billing.cancel_at_period_end',
                                                  )}
                                        </Button>
                                    </form>
                                ) : null}
                            </div>
                        ) : null}
                    </CardContent>
                </Card>
                <div className="grid gap-4 md:grid-cols-2">
                    {metrics.map((metric) => (
                        <Card key={metric.key}>
                            <CardHeader className="gap-2">
                                <div className="flex items-center justify-between gap-3">
                                    <CardTitle className="text-sm font-medium">
                                        {metric.label}
                                    </CardTitle>
                                    <span className="text-xs text-muted-foreground">
                                        {metric.reached
                                            ? t('billing.limit_reached')
                                            : metric.warning
                                              ? t('billing.approaching_limit')
                                              : t('billing.within_plan')}
                                    </span>
                                </div>
                                <p className="text-2xl font-semibold tracking-tight">
                                    {limitLabel(metric, t('billing.unlimited'))}
                                </p>
                            </CardHeader>
                            <CardContent>
                                <div
                                    className="h-2 overflow-hidden rounded-full bg-muted"
                                    role="progressbar"
                                    aria-label={`${metric.label} usage`}
                                    aria-valuemin={0}
                                    aria-valuemax={metric.limit ?? undefined}
                                    aria-valuenow={metric.used}
                                >
                                    <div
                                        className={`h-full rounded-full ${metric.reached ? 'bg-destructive' : metric.warning ? 'bg-amber-500' : 'bg-primary'}`}
                                        style={{
                                            width: `${Math.min(100, metric.percentage ?? 0)}%`,
                                        }}
                                    />
                                </div>
                                <p className="mt-2 text-xs text-muted-foreground">
                                    {metric.unlimited
                                        ? t('billing.unlimited_for_plan')
                                        : t('billing.period_limit', {
                                              percentage:
                                                  metric.percentage ?? 0,
                                          })}
                                </p>
                            </CardContent>
                        </Card>
                    ))}
                </div>
                <div className="space-y-4">
                    <Heading
                        variant="small"
                        title={t('billing.plan_comparison')}
                        description={t('billing.plan_registry_note')}
                    />
                    <div className="grid gap-4 xl:grid-cols-4">
                        {plans.map((plan) => (
                            <PlanCard
                                key={plan.key}
                                plan={plan}
                                currentPlan={summary.plan.key}
                                canManage={canManage}
                                onChoose={choosePlan}
                                processing={planForm.processing}
                            />
                        ))}
                    </div>
                </div>
            </div>
        </>
    );
}

Billing.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Billing',
            href: props.currentTeam
                ? `/${props.currentTeam.slug}/billing`
                : '/',
        },
    ],
});
