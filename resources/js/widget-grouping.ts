export type WidgetMessageTimestamp = {
    created_at?: string | null;
    role: 'user' | 'assistant' | 'system';
    source?: 'human' | 'system' | null;
};

export type VisualMessageMeta = {
    dateLabel: string | null;
    groupStart: boolean;
    groupEnd: boolean;
    timeLabel: string | null;
    datetime: string | null;
};

const GROUPING_WINDOW_MS = 5 * 60 * 1000;

export function visualMessageMeta(
    messages: WidgetMessageTimestamp[],
    index: number,
    now = new Date(),
): VisualMessageMeta {
    const message = messages[index];
    const date = parseDate(message.created_at);
    const previous = messages[index - 1];
    const next = messages[index + 1];
    const previousDate = parseDate(previous?.created_at);
    const nextDate = parseDate(next?.created_at);
    const samePreviousGroup =
        previous !== undefined &&
        sameSender(message, previous) &&
        date !== null &&
        previousDate !== null &&
        sameLocalDay(date, previousDate) &&
        Math.abs(date.getTime() - previousDate.getTime()) <= GROUPING_WINDOW_MS;
    const sameNextGroup =
        next !== undefined &&
        sameSender(message, next) &&
        date !== null &&
        nextDate !== null &&
        sameLocalDay(date, nextDate) &&
        Math.abs(nextDate.getTime() - date.getTime()) <= GROUPING_WINDOW_MS;

    return {
        dateLabel: date ? formatDateLabel(date, now) : null,
        groupStart: !samePreviousGroup,
        groupEnd: !sameNextGroup,
        timeLabel: date ? formatTime(date) : null,
        datetime: message.created_at ?? null,
    };
}

export function formatDateLabel(date: Date, now = new Date()): string {
    if (sameLocalDay(date, now)) {
        return 'Today';
    }

    const yesterday = new Date(now);
    yesterday.setDate(yesterday.getDate() - 1);

    if (sameLocalDay(date, yesterday)) {
        return 'Yesterday';
    }

    const includeYear = date.getFullYear() !== now.getFullYear();

    return new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
        year: includeYear ? 'numeric' : undefined,
    }).format(date);
}

export function formatTime(date: Date): string {
    return new Intl.DateTimeFormat(undefined, {
        hour: 'numeric',
        minute: '2-digit',
    }).format(date);
}

function parseDate(value: string | null | undefined): Date | null {
    if (!value) {
        return null;
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? null : date;
}

function sameSender(
    first: WidgetMessageTimestamp,
    second: WidgetMessageTimestamp,
): boolean {
    const sender = (message: WidgetMessageTimestamp): string =>
        message.role === 'assistant' && message.source === 'human'
            ? 'human'
            : message.role;

    return sender(first) === sender(second);
}

function sameLocalDay(first: Date, second: Date): boolean {
    return (
        first.getFullYear() === second.getFullYear() &&
        first.getMonth() === second.getMonth() &&
        first.getDate() === second.getDate()
    );
}
