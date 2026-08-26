import { createInertiaApp, router } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import { useEffect } from 'react';
import { I18nextProvider } from 'react-i18next';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import { changeLanguage, i18n } from '@/i18n';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

type LocaleBootstrapProps = PropsWithChildren<{
    locale?: unknown;
}>;

function LocaleBootstrap({ children, locale }: LocaleBootstrapProps) {
    const activeLocale = typeof locale === 'string' ? locale : 'en';

    useEffect(() => {
        void changeLanguage(activeLocale);
    }, [activeLocale]);

    useEffect(() => {
        return router.on('navigate', ({ detail }) => {
            const nextLocale = detail.page.props.locale;

            if (typeof nextLocale === 'string') {
                void changeLanguage(nextLocale);
            }
        });
    }, []);

    return <I18nextProvider i18n={i18n}>{children}</I18nextProvider>;
}

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
            case name.startsWith('teams/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app, { page }) {
        return (
            <TooltipProvider delayDuration={0}>
                <LocaleBootstrap locale={page.props.locale}>
                    {app}
                </LocaleBootstrap>
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
