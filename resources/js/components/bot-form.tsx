import { Form, Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/bots';
import type { Bot } from '@/types';
import type { RouteFormDefinition } from '@/wayfinder';

type Props = {
    action: RouteFormDefinition<'post'>;
    bot?: Bot;
    currentTeamSlug: string;
    submitLabel: string;
};

export default function BotForm({
    action,
    bot,
    currentTeamSlug,
    submitLabel,
}: Props) {
    const { t } = useTranslation();

    return (
        <Form
            {...action}
            options={{ preserveScroll: true }}
            className="space-y-6"
        >
            {({ errors, processing }) => (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="name">{t('common.name')}</Label>
                        <Input
                            id="name"
                            name="name"
                            defaultValue={bot?.name ?? ''}
                            placeholder="Support assistant"
                            required
                            autoFocus
                            data-test="bot-name-input"
                        />
                        <InputError message={errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="slug">Slug</Label>
                        <Input
                            id="slug"
                            name="slug"
                            defaultValue={bot?.slug ?? ''}
                            placeholder="support-assistant"
                            required
                            data-test="bot-slug-input"
                        />
                        <p className="text-sm text-muted-foreground">
                            Used as the bot's stable identifier within this
                            team.
                        </p>
                        <InputError message={errors.slug} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="default_language">
                            {t('common.language')}
                        </Label>
                        <Input
                            id="default_language"
                            name="default_language"
                            defaultValue={bot?.defaultLanguage ?? 'en'}
                            maxLength={10}
                            placeholder="en"
                            required
                            data-test="bot-language-input"
                        />
                        <InputError message={errors.default_language} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="instructions">Instructions</Label>
                        <textarea
                            id="instructions"
                            name="instructions"
                            defaultValue={bot?.instructions ?? ''}
                            placeholder="Describe how this assistant should respond."
                            rows={5}
                            className="flex min-h-20 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                            data-test="bot-instructions-input"
                        />
                        <InputError message={errors.instructions} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="welcome_message">Welcome message</Label>
                        <textarea
                            id="welcome_message"
                            name="welcome_message"
                            defaultValue={bot?.welcomeMessage ?? ''}
                            placeholder="Hello! How can I help?"
                            rows={3}
                            className="flex min-h-20 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                            data-test="bot-welcome-input"
                        />
                        <InputError message={errors.welcome_message} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="fallback_message">
                            Fallback message
                        </Label>
                        <textarea
                            id="fallback_message"
                            name="fallback_message"
                            defaultValue={bot?.fallbackMessage ?? ''}
                            placeholder="I could not find a good answer for that."
                            rows={3}
                            className="flex min-h-20 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                            data-test="bot-fallback-input"
                        />
                        <InputError message={errors.fallback_message} />
                    </div>

                    <div className="flex items-center gap-4">
                        <Button
                            type="submit"
                            disabled={processing}
                            data-test="bot-save-button"
                        >
                            {processing ? t('common.saving') : submitLabel}
                        </Button>
                        <Button variant="ghost" asChild>
                            <Link href={index(currentTeamSlug).url}>
                                {t('common.cancel')}
                            </Link>
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
