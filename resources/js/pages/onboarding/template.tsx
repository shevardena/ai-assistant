import { Form, Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    Check,
    CircleHelp,
    Database,
    GitBranch,
    Globe,
    ShieldCheck,
    Zap,
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
import { apply as applyTemplate } from '@/routes/onboarding';
import type {
    BusinessTemplateDefinition,
    OnboardingTemplateProps,
} from '@/types';

function importanceVariant(
    importance: 'required' | 'recommended' | 'optional',
): 'default' | 'secondary' | 'outline' {
    return importance === 'required'
        ? 'default'
        : importance === 'recommended'
          ? 'secondary'
          : 'outline';
}

function categoryIcon(category: string) {
    if (category === 'data_knowledge') {
        return Database;
    }

    if (category === 'channels') {
        return Globe;
    }

    if (category === 'automation') {
        return GitBranch;
    }

    if (category === 'actions') {
        return Zap;
    }

    return CircleHelp;
}

export default function OnboardingTemplate({
    template,
    backUrl,
}: OnboardingTemplateProps) {
    const { t } = useTranslation();
    const { currentTeam } = usePage().props;

    if (!currentTeam) {
        return null;
    }

    const requirementsByCategory = template.requirements.reduce<
        Record<string, BusinessTemplateDefinition['requirements']>
    >((groups, requirement) => {
        const category =
            requirement.type === 'knowledge' || requirement.type === 'catalog'
                ? 'data_knowledge'
                : requirement.type === 'live_read'
                  ? 'live_integrations'
                  : requirement.type === 'live_write'
                    ? 'actions'
                    : requirement.type === 'workflow'
                      ? 'automation'
                      : 'channels';
        groups[category] = [...(groups[category] ?? []), requirement];

        return groups;
    }, {});

    const categories = Object.entries(requirementsByCategory);

    return (
        <>
            <Head
                title={`${t(template.nameKey)} · ${t('templates.setup.title')}`}
            />

            <div className="flex max-w-6xl flex-col gap-8 p-4 md:p-6">
                <Button variant="ghost" className="w-fit" asChild>
                    <Link href={backUrl}>
                        <ArrowLeft />
                        {t('templates.actions.all_templates')}
                    </Link>
                </Button>

                <div className="space-y-3">
                    <Heading
                        variant="small"
                        title={t(template.nameKey)}
                        description={t(template.descriptionKey)}
                    />
                    <p className="text-sm text-muted-foreground">
                        {t(template.bestForKey)}
                    </p>
                </div>

                <div className="grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
                    <div className="flex flex-col gap-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>
                                    {t('templates.outcomes.title')}
                                </CardTitle>
                                <CardDescription>
                                    {t('templates.outcomes.description')}
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="grid gap-3 sm:grid-cols-2">
                                {template.outcomeKeys.map((outcomeKey) => (
                                    <div
                                        key={outcomeKey}
                                        className="flex items-start gap-2 text-sm"
                                    >
                                        <Check className="mt-0.5 size-4 shrink-0 text-primary" />
                                        <span>
                                            {t(
                                                `templates.outcomes.${outcomeKey}`,
                                            )}
                                        </span>
                                    </div>
                                ))}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>
                                    {t('templates.setup.overview')}
                                </CardTitle>
                                <CardDescription>
                                    {t('templates.setup.overview_description')}
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-8">
                                {categories.map(([category, requirements]) => {
                                    const Icon = categoryIcon(category);

                                    return (
                                        <section
                                            key={category}
                                            className="space-y-3"
                                        >
                                            <div className="flex items-center gap-2">
                                                <Icon className="size-4 text-primary" />
                                                <h3 className="font-semibold">
                                                    {t(
                                                        `templates.categories.${category}`,
                                                    )}
                                                </h3>
                                            </div>
                                            <div className="flex flex-col gap-3">
                                                {requirements.map(
                                                    (requirement) => (
                                                        <div
                                                            key={
                                                                requirement.key
                                                            }
                                                            className="rounded-lg border p-4"
                                                        >
                                                            <div className="flex flex-wrap items-center gap-2">
                                                                <p className="font-medium">
                                                                    {t(
                                                                        requirement.titleKey,
                                                                    )}
                                                                </p>
                                                                <Badge
                                                                    variant={importanceVariant(
                                                                        requirement.importance,
                                                                    )}
                                                                >
                                                                    {t(
                                                                        `templates.importance.${requirement.importance}`,
                                                                    )}
                                                                </Badge>
                                                                {requirement.dataMode ? (
                                                                    <Badge variant="outline">
                                                                        {t(
                                                                            `templates.data_modes.${requirement.dataMode}`,
                                                                        )}
                                                                    </Badge>
                                                                ) : null}
                                                                {requirement.supportStatus ===
                                                                'future_custom' ? (
                                                                    <Badge variant="outline">
                                                                        {t(
                                                                            'templates.support.future_custom',
                                                                        )}
                                                                    </Badge>
                                                                ) : null}
                                                            </div>
                                                            <p className="mt-2 text-sm text-muted-foreground">
                                                                {t(
                                                                    requirement.descriptionKey,
                                                                )}
                                                            </p>
                                                            <p className="mt-2 text-sm">
                                                                <span className="font-medium">
                                                                    {t(
                                                                        'templates.setup.why',
                                                                    )}
                                                                </span>{' '}
                                                                {t(
                                                                    requirement.whyKey,
                                                                )}
                                                            </p>
                                                            {requirement.guidanceKey ? (
                                                                <p className="mt-2 text-xs text-muted-foreground">
                                                                    {t(
                                                                        requirement.guidanceKey,
                                                                    )}
                                                                </p>
                                                            ) : null}
                                                            {requirement
                                                                .capabilities
                                                                .length > 0 ? (
                                                                <div className="mt-3 flex flex-wrap gap-2">
                                                                    {requirement.capabilities.map(
                                                                        (
                                                                            capability,
                                                                        ) => (
                                                                            <Badge
                                                                                key={
                                                                                    capability
                                                                                }
                                                                                variant="secondary"
                                                                            >
                                                                                {t(
                                                                                    `templates.capabilities.${capability}`,
                                                                                )}
                                                                            </Badge>
                                                                        ),
                                                                    )}
                                                                </div>
                                                            ) : null}
                                                        </div>
                                                    ),
                                                )}
                                            </div>
                                        </section>
                                    );
                                })}
                            </CardContent>
                        </Card>
                    </div>

                    <div className="flex flex-col gap-6">
                        <Card className="h-fit">
                            <CardHeader>
                                <CardTitle>
                                    {t('templates.setup.create_title')}
                                </CardTitle>
                                <CardDescription>
                                    {t('templates.setup.create_description')}
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-5">
                                <div className="rounded-lg border bg-muted/30 p-4 text-sm text-muted-foreground">
                                    <div className="flex gap-2 font-medium text-foreground">
                                        <ShieldCheck className="size-4 text-primary" />
                                        {t('templates.setup.safe_scaffolding')}
                                    </div>
                                    <p className="mt-2">
                                        {t(
                                            'templates.setup.safe_scaffolding_description',
                                        )}
                                    </p>
                                </div>

                                <Form {...applyTemplate.form(currentTeam.slug)}>
                                    {({ errors, processing }) => (
                                        <div className="space-y-4">
                                            <input
                                                type="hidden"
                                                name="template_key"
                                                value={template.key}
                                            />
                                            <div className="space-y-2">
                                                <label
                                                    htmlFor="bot_name"
                                                    className="text-sm font-medium"
                                                >
                                                    {t(
                                                        'templates.setup.bot_name',
                                                    )}
                                                </label>
                                                <input
                                                    id="bot_name"
                                                    name="bot_name"
                                                    defaultValue={
                                                        template.recommendedBotName
                                                    }
                                                    className="h-10 w-full rounded-md border bg-background px-3 text-sm outline-none focus:ring-2 focus:ring-ring"
                                                    maxLength={120}
                                                />
                                                {errors.bot_name ? (
                                                    <p className="text-sm text-destructive">
                                                        {errors.bot_name}
                                                    </p>
                                                ) : null}
                                            </div>
                                            {errors.template_key ? (
                                                <p className="text-sm text-destructive">
                                                    {errors.template_key}
                                                </p>
                                            ) : null}
                                            <Button
                                                type="submit"
                                                className="w-full"
                                                disabled={processing}
                                            >
                                                {processing
                                                    ? t(
                                                          'templates.actions.creating',
                                                      )
                                                    : t(
                                                          'templates.actions.use_template',
                                                      )}
                                            </Button>
                                        </div>
                                    )}
                                </Form>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>
                                    {t('templates.setup.channels')}
                                </CardTitle>
                                <CardDescription>
                                    {t('templates.setup.channels_description')}
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="flex flex-wrap gap-2">
                                {template.channelRecommendations.map(
                                    (channel) => (
                                        <Badge
                                            key={channel.key}
                                            variant={importanceVariant(
                                                channel.importance,
                                            )}
                                        >
                                            {t(channel.titleKey)} ·{' '}
                                            {t(
                                                `templates.importance.${channel.importance}`,
                                            )}
                                        </Badge>
                                    ),
                                )}
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>
        </>
    );
}
