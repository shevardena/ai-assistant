import type { ConfirmationBlockAppearance } from '@/components/confirmation-block';
import type { TrackingBlock as TrackingBlockData } from '@/types';

export function TrackingBlock({
    block,
    appearance,
}: {
    block: TrackingBlockData;
    appearance?: ConfirmationBlockAppearance;
}) {
    const rows = canonicalRows(block);

    if (rows.length === 0 && !block.data.status && !block.data.tracking_url) {
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
            aria-label="Shipment tracking"
        >
            <h3 className="font-medium">Shipment tracking</h3>
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
            {rows.length > 0 || block.data.fields.length > 0 ? (
                <dl className="grid gap-2">
                    {[...rows, ...block.data.fields].map((field) => (
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
            {safeUrl(block.data.tracking_url) ? (
                <a
                    href={safeUrl(block.data.tracking_url) ?? undefined}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="inline-flex w-fit rounded-md px-3 py-2 text-sm font-medium hover:opacity-90"
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
                >
                    Track shipment
                </a>
            ) : null}
        </section>
    );
}

function canonicalRows(
    block: TrackingBlockData,
): TrackingBlockData['data']['fields'] {
    const rows: TrackingBlockData['data']['fields'] = [];

    for (const [key, label, value] of [
        ['carrier', 'Carrier', block.data.carrier],
        [
            'tracking_reference',
            'Tracking number',
            block.data.tracking_reference,
        ],
        [
            'estimated_delivery',
            'Estimated delivery',
            block.data.estimated_delivery,
        ],
        ['latest_event', 'Latest update', block.data.latest_event],
    ] as const) {
        if (value !== undefined && value !== null && value !== '') {
            rows.push({ key, label, value });
        }
    }

    return rows;
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

function safeUrl(value: string | undefined): string | null {
    if (!value) {
        return null;
    }

    try {
        const url = new URL(value);

        return url.protocol === 'http:' || url.protocol === 'https:'
            ? value
            : null;
    } catch {
        return null;
    }
}

function safeColor(value: string | undefined, fallback: string): string {
    return value && /^#[0-9a-f]{6}$/i.test(value) ? value : fallback;
}
