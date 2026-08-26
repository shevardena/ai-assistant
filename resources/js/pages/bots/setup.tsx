import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    Check,
    ExternalLink,
    GitBranch,
    Globe,
    ShieldCheck,
    Sparkles,
} from 'lucide-react';
import { useTranslation } from 'react-i18next';
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
import type { BotSetupProps, OnboardingRequirement } from '@/types';

function statusVariant(
    status: OnboardingRequirement['status'],
): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (status === 'ready') {
        return 'default';
    }

    if (status === 'unavailable') {
        return 'secondary';
    }

    return 'outline';
}

function RequirementRow({
    requirement,
}: {
    requirement: OnboardingRequirement;
}) {
    const { t } = useTranslation();

    return (
        <div className="flex flex-col gap-4 rounded-lg border p-4 lg:flex-row lg:items-start lg:justify-between">
            <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                    <p className="font-medium">{t(requirement.titleKey)}</p>
                    <Badge variant={statusVariant(requirement.status)}>
                        {t(`templates.status.${requirement.status}`)}
                    </Badge>
                    <Badge variant="outline">
                        {t(`templates.importance.${requirement.importance}`)}
                    </Badge>
                    {requirement.dataMode ? (
                        <Badge variant="outline">
                            {t(`templates.data_modes.${requirement.dataMode}`)}
                        </Badge>
                    ) : null}
                </div>
                <p className="mt-2 text-sm text-muted-foreground">
                    {t(requirement.descriptionKey)}
                </p>
                <p className="mt-2 text-sm">
                    <span className="font-medium">
                        {t('templates.setup.why')}
                    </span>{' '}
                    {t(requirement.whyKey)}
                </p>
                <p className="mt-2 text-xs text-muted-foreground">
                    {t(requirement.statusReasonKey)}
                </p>
                {requirement.suggestedFields.length > 0 ? (
                    <p className="mt-2 text-xs text-muted-foreground">
                        {t('templates.setup.suggested_fields')}:{' '}
                        {requirement.suggestedFields.join(', ')}
                    </p>
                ) : null}
                {requirement.capabilities.length > 0 ? (
                    <div className="mt-3 flex flex-wrap gap-2">
                        {requirement.capabilities.map((capability) => (
                            <Badge key={capability.key} variant="secondary">
                                {t(capability.labelKey)}
                            </Badge>
                        ))}
                    </div>
                ) : null}
            </div>
            {requirement.setup.url ? (
                <Button size="sm" variant="outline" asChild>
                    <Link href={requirement.setup.url}>
                        {t(requirement.setup.labelKey)}
                        <ExternalLink />
                    </Link>
                </Button>
            ) : (
                <Badge variant="secondary" className="w-fit">
                    {t('templates.support.not_available_yet')}
                </Badge>
            )}
        </div>
    );
}

export default function BotSetup({ bot, template, checklist }: BotSetupProps) {
    const { t } = useTranslation();
    const { currentTeam } = usePage().props;

    if (!currentTeam) {
        return null;
    }

    return (
        <>
            <Head
                title={`${t(template.nameKey)} · ${t('templates.setup.title')}`}
            />

            <div className="flex max-w-6xl flex-col gap-8 p-4 md:p-6">
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                    <Heading
                        variant="small"
                        title={`${bot.name} · ${t('templates.setup.title')}`}
                        description={t(template.descriptionKey)}
                    />
                    <Button variant="outline" asChild>
                        <Link
                            href={
                                botShow({
                                    current_team: currentTeam.slug,
                                    bot: bot.id,
                                }).url
                            }
                        >
                            {t('templates.actions.open_bot')}
                            <ArrowRight />
                        </Link>
                    </Button>
                </div>

                <Card>
                    <CardContent className="flex flex-col gap-5 p-6 sm:flex-row sm:items-center">
                        <div className="flex size-16 shrink-0 items-center justify-center rounded-full bg-primary/10 text-lg font-semibold text-primary">
                            {checklist.progress.percentage}%
                        </div>
                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-center gap-2">
                                <h2 className="font-semibold">
                                    {t('templates.setup.launch_readiness')}
                                </h2>
                                <Badge
                                    variant={
                                        checklist.progress.launchReady
                                            ? 'default'
                                            : 'secondary'
                                    }
                                >
                                    {checklist.progress.launchReady
                                        ? t('templates.status.ready')
                                        : t('templates.status.not_configured')}
                                </Badge>
                            </div>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {t('templates.setup.progress_summary', {
                                    completed: checklist.progress.completed,
                                    total: checklist.progress.total,
                                })}
                            </p>
                            <div className="mt-3 h-2 overflow-hidden rounded-full bg-muted">
                                <div
                                    className="h-full rounded-full bg-primary transition-all"
                                    style={{
                                        width: `${checklist.progress.percentage}%`,
                                    }}
                                />
                            </div>
                            <p className="mt-2 text-xs text-muted-foreground">
                                {t('templates.setup.optional_does_not_block')}
                            </p>
                        </div>
                    </CardContent>
                </Card>

                {checklist.groups.map((group) => (
                    <Card key={group.key}>
                        <CardHeader>
                            <CardTitle>{t(group.titleKey)}</CardTitle>
                            <CardDescription>
                                {t(
                                    `templates.categories.${group.key}_description`,
                                )}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-3">
                            {group.requirements.map((requirement) => (
                                <RequirementRow
                                    key={requirement.key}
                                    requirement={requirement}
                                />
                            ))}
                        </CardContent>
                    </Card>
                ))}

                {checklist.groups.length === 0 ? (
                    <Card>
                        <CardContent className="flex items-center gap-3 p-6 text-sm text-muted-foreground">
                            <Sparkles className="size-4 text-primary" />
                            {t('templates.setup.custom_description')}
                        </CardContent>
                    </Card>
                ) : null}

                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <GitBranch className="size-4 text-primary" />
                                {t('templates.categories.automation')}
                            </CardTitle>
                            <CardDescription>
                                {t('templates.setup.automation_description')}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-3">
                            {checklist.workflows.map((workflow) => (
                                <div
                                    key={workflow.key}
                                    className="flex items-center justify-between gap-3 rounded-lg border p-3"
                                >
                                    <div className="min-w-0">
                                        <p className="font-medium">
                                            {t(workflow.titleKey)}
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            {t(workflow.descriptionKey)}
                                        </p>
                                    </div>
                                    {workflow.setup.url ? (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            asChild
                                        >
                                            <Link href={workflow.setup.url}>
                                                {t(workflow.setup.labelKey)}
                                            </Link>
                                        </Button>
                                    ) : null}
                                </div>
                            ))}
                            {checklist.workflows.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    {t('templates.setup.no_recommendations')}
                                </p>
                            ) : null}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Globe className="size-4 text-primary" />
                                {t('templates.setup.channels')}
                            </CardTitle>
                            <CardDescription>
                                {t('templates.setup.channels_description')}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-3">
                            {checklist.channels.map((channel) => (
                                <div
                                    key={channel.key}
                                    className="flex items-center justify-between gap-3 rounded-lg border p-3"
                                >
                                    <div className="min-w-0">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <p className="font-medium">
                                                {t(channel.titleKey)}
                                            </p>
                                            <Badge variant="outline">
                                                {t(
                                                    `templates.importance.${channel.importance}`,
                                                )}
                                            </Badge>
                                        </div>
                                        <p className="text-sm text-muted-foreground">
                                            {t(channel.descriptionKey)}
                                        </p>
                                    </div>
                                    {channel.setup.url ? (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            asChild
                                        >
                                            <Link href={channel.setup.url}>
                                                {t(channel.setup.labelKey)}
                                            </Link>
                                        </Button>
                                    ) : null}
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Check className="size-4 text-primary" />
                            {t('templates.setup.testing_title')}
                        </CardTitle>
                        <CardDescription>
                            {t('templates.setup.testing_description')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-wrap items-center gap-3">
                        {checklist.suggestedTests.map((testKey) => (
                            <Badge key={testKey} variant="outline">
                                {t(testKey)}
                            </Badge>
                        ))}
                        <Button size="sm" variant="outline" asChild>
                            <Link
                                href={
                                    checklist.steps.find(
                                        (step) => step.key === 'tests',
                                    )?.actionUrl ?? '#'
                                }
                            >
                                {t('templates.actions.run_bot_test')}
                            </Link>
                        </Button>
                    </CardContent>
                </Card>

                <div className="flex gap-3 rounded-lg border bg-muted/30 p-4 text-sm text-muted-foreground">
                    <ShieldCheck className="mt-0.5 size-4 shrink-0 text-primary" />
                    <p>{t('templates.setup.safety_note')}</p>
                </div>
            </div>
        </>
    );
}
