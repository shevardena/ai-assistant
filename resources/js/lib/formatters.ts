import { i18n } from '@/i18n';

const localeMap: Record<string, string> = {
    en: 'en',
    ka: 'ka',
    ru: 'ru',
    uk: 'uk',
    pl: 'pl',
    de: 'de',
    es: 'es',
    pt: 'pt',
};

function intlLocale(locale: string): string {
    return localeMap[locale] ?? localeMap.en;
}

export function formatDate(
    value: string | number | Date | null | undefined,
    locale = i18n.language || 'en',
): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const date = value instanceof Date ? value : new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    return new Intl.DateTimeFormat(intlLocale(locale), {
        dateStyle: 'medium',
    }).format(date);
}

export function formatDateTime(
    value: string | number | Date | null | undefined,
    locale = i18n.language || 'en',
): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const date = value instanceof Date ? value : new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    return new Intl.DateTimeFormat(intlLocale(locale), {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(date);
}

export function formatNumber(
    value: number,
    locale = i18n.language || 'en',
): string {
    return new Intl.NumberFormat(intlLocale(locale)).format(value);
}

export function formatCurrency(
    value: number,
    currency = 'USD',
    locale = i18n.language || 'en',
): string {
    return new Intl.NumberFormat(intlLocale(locale), {
        style: 'currency',
        currency,
    }).format(value);
}

export function formatRelativeTime(
    value: string | number | Date,
    locale = i18n.language || 'en',
    now = new Date(),
): string {
    const date = value instanceof Date ? value : new Date(value);
    const seconds = Math.round((date.getTime() - now.getTime()) / 1000);
    const absoluteSeconds = Math.abs(seconds);
    const [amount, unit] =
        absoluteSeconds < 60
            ? [seconds, 'second']
            : absoluteSeconds < 3600
              ? [Math.round(seconds / 60), 'minute']
              : absoluteSeconds < 86400
                ? [Math.round(seconds / 3600), 'hour']
                : [Math.round(seconds / 86400), 'day'];

    return new Intl.RelativeTimeFormat(intlLocale(locale), {
        numeric: 'auto',
    }).format(amount, unit as Intl.RelativeTimeFormatUnit);
}
