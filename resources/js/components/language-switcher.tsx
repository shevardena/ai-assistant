import { router, usePage } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { changeLanguage } from '@/i18n';
import { update } from '@/routes/locale';
import type { LocaleMetadata } from '@/types';

const localeFlags: Record<string, string> = {
    en: '🇬🇧',
    ka: '🇬🇪',
    ru: '🇷🇺',
    uk: '🇺🇦',
    pl: '🇵🇱',
    de: '🇩🇪',
    es: '🇪🇸',
    pt: '🇵🇹',
};

export function LanguageSwitcher() {
    const { t } = useTranslation();
    const { locale, supportedLocales } = usePage().props;
    const persistedLocale = typeof locale === 'string' ? locale : 'en';
    const [pendingLocale, setPendingLocale] = useState<string | null>(null);
    const queuedLocale = useRef<string | null>(null);
    const activeLocale = pendingLocale ?? persistedLocale;
    const locales = (supportedLocales ?? []) as LocaleMetadata[];
    const activeFlag = localeFlags[activeLocale] ?? '🌐';

    function submitLocale(nextLocale: string): void {
        setPendingLocale(nextLocale);
        router.patch(
            update.url(),
            { locale: nextLocale },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    void changeLanguage(nextLocale);
                },
                onError: () => {
                    void changeLanguage(persistedLocale);
                },
                onFinish: () => {
                    const queued = queuedLocale.current;
                    queuedLocale.current = null;

                    if (queued !== null && queued !== nextLocale) {
                        submitLocale(queued);

                        return;
                    }

                    setPendingLocale(null);
                },
            },
        );
    }

    function selectLocale(nextLocale: string): void {
        if (nextLocale === activeLocale) {
            return;
        }

        window.setTimeout(() => {
            if (pendingLocale !== null) {
                queuedLocale.current = nextLocale;
                setPendingLocale(nextLocale);

                return;
            }

            submitLocale(nextLocale);
        }, 0);
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-9 text-base"
                    aria-label={t('common.language')}
                >
                    <span aria-hidden="true">{activeFlag}</span>
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                {locales.map((supportedLocale) => (
                    <DropdownMenuItem
                        key={supportedLocale.code}
                        onSelect={() => selectLocale(supportedLocale.code)}
                    >
                        <span aria-hidden="true" className="text-base">
                            {localeFlags[supportedLocale.code] ?? '🌐'}
                        </span>
                        <span className="flex-1">
                            {supportedLocale.nativeName}
                        </span>
                        {supportedLocale.code === activeLocale ? (
                            <Check className="size-4" />
                        ) : null}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
