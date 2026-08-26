import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
import de from '@/locales/de.json';
import en from '@/locales/en.json';
import es from '@/locales/es.json';
import ka from '@/locales/ka.json';
import pl from '@/locales/pl.json';
import pt from '@/locales/pt.json';
import ru from '@/locales/ru.json';
import uk from '@/locales/uk.json';

const resources = {
    en: { translation: en },
    ka: { translation: ka },
    ru: { translation: ru },
    uk: { translation: uk },
    pl: { translation: pl },
    de: { translation: de },
    es: { translation: es },
    pt: { translation: pt },
};

const initialization = i18n.use(initReactI18next).init({
    resources,
    lng: 'en',
    fallbackLng: 'en',
    defaultNS: 'translation',
    ns: ['translation'],
    interpolation: {
        escapeValue: false,
    },
    react: {
        useSuspense: false,
    },
    missingKeyHandler: (locales, namespace, key) => {
        if (import.meta.env.DEV) {
            console.warn(
                `[i18n] Missing translation "${key}" in ${locales.join(', ')}`,
            );
        }
    },
});

let languageChangeQueue = Promise.resolve();

export async function changeLanguage(locale: string): Promise<string> {
    const nextLocale = Object.hasOwn(resources, locale) ? locale : 'en';

    const change = languageChangeQueue.then(async () => {
        try {
            await initialization;

            if (i18n.language !== nextLocale) {
                await i18n.changeLanguage(nextLocale);
            }
        } catch (error) {
            if (import.meta.env.DEV) {
                console.error('[i18n] Failed to change language.', error);
            }
        }

        if (typeof document !== 'undefined') {
            document.documentElement.lang = nextLocale;
        }

        return nextLocale;
    });

    languageChangeQueue = change.then(
        () => undefined,
        () => undefined,
    );

    return change;
}

export { i18n };
