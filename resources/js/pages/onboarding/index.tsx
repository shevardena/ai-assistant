import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowRight, Bot, Layers3, Sparkles } from 'lucide-react';
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
import { template as templateRoute } from '@/routes/onboarding';
import type { BusinessTemplateDefinition, OnboardingIndexProps } from '@/types';

function requirementCount(
    template: BusinessTemplateDefinition,
    importance: 'required' | 'recommended' | 'optional',
): number {
    return template.requirements.filter(
        (requirement) => requirement.importance === importance,
    ).length;
}

export default function OnboardingIndex({
    templates,
    hasBots,
    scratchUrl,
}: OnboardingIndexProps) {
    const { t } = useTranslation();
    const { currentTeam } = usePage().props;

    if (!currentTeam) {
        return null;
    }

    return (
        <>
            <Head title={t('navigation.onboarding')} />

            <div className="flex flex-col gap-8 p-4 md:p-6">
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                    <Heading
                        variant="small"
                        title={
                            hasBots
                                ? t('common.set_up_another_assistant')
                                : t('common.set_up_first_assistant')
                        }
                        description={t('templates.setup.index_description')}
                    />
                    <Button variant="outline" asChild>
                        <Link href={scratchUrl}>
                            <Bot />
                            {t('templates.actions.start_from_scratch')}
                        </Link>
                    </Button>
                </div>

                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {templates.map((template) => (
                        <Card
                            key={template.key}
                            className="flex h-full flex-col"
                        >
                            <CardHeader>
                                <div className="flex items-start justify-between gap-4">
                                    <div className="flex size-11 items-center justify-center rounded-xl bg-primary/10 text-primary">
                                        <Sparkles className="size-5" />
                                    </div>
                                    <Badge variant="secondary">
                                        {template.capabilityCount}{' '}
                                        {t(
                                            'templates.card.supported_capabilities',
                                        )}
                                    </Badge>
                                </div>
                                <CardTitle className="pt-2">
                                    {t(template.nameKey)}
                                </CardTitle>
                                <CardDescription>
                                    {t(template.descriptionKey)}
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="flex flex-1 flex-col gap-5">
                                <p className="text-sm text-muted-foreground">
                                    {t(template.bestForKey)}
                                </p>
                                <div className="grid grid-cols-3 gap-2 rounded-lg border bg-muted/20 p-3 text-center text-xs">
                                    <div>
                                        <p className="font-semibold text-foreground">
                                            {requirementCount(
                                                template,
                                                'required',
                                            )}
                                        </p>
                                        <p className="text-muted-foreground">
                                            {t('templates.importance.required')}
                                        </p>
                                    </div>
                                    <div>
                                        <p className="font-semibold text-foreground">
                                            {requirementCount(
                                                template,
                                                'recommended',
                                            )}
                                        </p>
                                        <p className="text-muted-foreground">
                                            {t(
                                                'templates.importance.recommended',
                                            )}
                                        </p>
                                    </div>
                                    <div>
                                        <p className="font-semibold text-foreground">
                                            {requirementCount(
                                                template,
                                                'optional',
                                            )}
                                        </p>
                                        <p className="text-muted-foreground">
                                            {t('templates.importance.optional')}
                                        </p>
                                    </div>
                                </div>
                                <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                    <Layers3 className="size-3.5" />
                                    {t('templates.card.core_setup')}
                                </div>
                                <Button className="mt-auto" asChild>
                                    <Link
                                        href={
                                            templateRoute({
                                                current_team: currentTeam.slug,
                                                template: template.key,
                                            }).url
                                        }
                                    >
                                        {t('templates.actions.review_setup')}
                                        <ArrowRight />
                                    </Link>
                                </Button>
                            </CardContent>
                        </Card>
                    ))}
                </div>
            </div>
        </>
    );
}
