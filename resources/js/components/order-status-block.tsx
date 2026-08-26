import type { ConfirmationBlockAppearance } from '@/components/confirmation-block';
import type { OrderStatusBlock as OrderStatusBlockData } from '@/types';

export function OrderStatusBlock({
    block,
    appearance,
}: {
    block: OrderStatusBlockData;
    appearance?: ConfirmationBlockAppearance;
}) {
    if (!block.data.status && block.data.fields.length === 0) {
        return null;
    }

    return (
        <section
            className="mt-3 grid gap-3 rounded-lg border p-3 text-sm"
            style={{
                backgroundColor: safeColor(
                    appearance?.backgroundColor,
                    '#ffffff',
                ),
                color: safeColor(appearance?.textColor, '#171717'),
            }}
            aria-label="Order status"
        >
            <h3 className="font-medium">Order status</h3>
            {block.data.status ? (
                <p
                    className="w-fit rounded-full px-3 py-1 font-medium"
                    style={{
                        backgroundColor: safeColor(
                            appearance?.buttonColor,
                            '#171717',
                        ),
                        color: safeColor(
                            appearance?.buttonTextColor,
                            '#ffffff',
                        ),
                    }}
                    role="status"
                >
                    {formatStatus(block.data.status)}
                </p>
            ) : null}
            {block.data.fields.length > 0 ? (
                <dl className="grid gap-2">
                    {block.data.fields.map((field) => (
                        <div
                            key={field.key}
                            className="grid gap-1 border-t pt-2 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-baseline sm:gap-4"
                        >
                            <dt className="text-xs opacity-70">
                                {field.label}
                            </dt>
                            <dd className="font-medium break-words sm:text-right">
                                {formatValue(field.value)}
                            </dd>
                        </div>
                    ))}
                </dl>
            ) : null}
        </section>
    );
}

function formatStatus(status: string): string {
    return status
        .replace(/[_-]+/g, ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());
}

function formatValue(value: string | number | boolean | null): string {
    if (value === null || value === '') {
        return '—';
    }

    if (typeof value === 'boolean') {
        return value ? 'Yes' : 'No';
    }

    if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)) {
        const date = new Date(`${value}T00:00:00Z`);

        if (!Number.isNaN(date.getTime())) {
            return new Intl.DateTimeFormat(undefined, {
                day: 'numeric',
                month: 'short',
                timeZone: 'UTC',
                year: 'numeric',
            }).format(date);
        }
    }

    return String(value);
}

function safeColor(value: string | undefined, fallback: string): string {
    return value && /^#[0-9a-f]{6}$/i.test(value) ? value : fallback;
}
