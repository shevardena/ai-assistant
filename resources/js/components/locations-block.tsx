import { ExternalLink, MapPin } from 'lucide-react';

import type { ConfirmationBlockAppearance } from '@/components/confirmation-block';
import type {
    LocationField,
    LocationItem,
    LocationsBlock as LocationsBlockData,
} from '@/types';

export function LocationsBlock({
    block,
    appearance,
}: {
    block: LocationsBlockData;
    appearance?: ConfirmationBlockAppearance;
}) {
    const locations = block.data.locations.filter(hasUsefulContent);

    if (locations.length === 0) {
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
            aria-label="Nearby locations"
        >
            <h3 className="font-medium">Nearby locations</h3>
            <div className="grid gap-3">
                {locations.map((location, index) => (
                    <article
                        key={`${location.name ?? 'location'}-${index}`}
                        className="grid gap-2 rounded-md border p-3"
                    >
                        <div className="flex flex-wrap items-start justify-between gap-2">
                            <div className="flex min-w-0 items-start gap-2">
                                {hasCoordinates(location) ? (
                                    <span
                                        className="mt-0.5 shrink-0 opacity-70"
                                        title="Coordinates available"
                                    >
                                        <MapPin
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                        <span className="sr-only">
                                            Coordinates available
                                        </span>
                                    </span>
                                ) : null}
                                <h4 className="min-w-0 font-medium break-words">
                                    {location.name ?? 'Location'}
                                </h4>
                            </div>
                            {formatDistance(location) ? (
                                <span className="shrink-0 text-xs opacity-70">
                                    {formatDistance(location)}
                                </span>
                            ) : null}
                        </div>

                        {formatAddress(location) ? (
                            <p className="break-words opacity-85">
                                {formatAddress(location)}
                            </p>
                        ) : null}

                        {location.hours || location.phone ? (
                            <dl className="grid gap-1 text-xs sm:grid-cols-2 sm:gap-x-4">
                                {location.hours ? (
                                    <div className="grid gap-0.5">
                                        <dt className="opacity-65">Hours</dt>
                                        <dd className="break-words">
                                            {location.hours}
                                        </dd>
                                    </div>
                                ) : null}
                                {location.phone ? (
                                    <div className="grid gap-0.5">
                                        <dt className="opacity-65">Phone</dt>
                                        <dd className="break-words">
                                            {location.phone}
                                        </dd>
                                    </div>
                                ) : null}
                            </dl>
                        ) : null}

                        {location.fields && location.fields.length > 0 ? (
                            <dl className="grid gap-1 border-t pt-2 text-xs sm:grid-cols-2 sm:gap-x-4">
                                {location.fields.map((field) => (
                                    <LocationFieldRow
                                        key={field.key}
                                        field={field}
                                    />
                                ))}
                            </dl>
                        ) : null}

                        <LocationLink
                            url={location.url}
                            appearance={appearance}
                        />
                    </article>
                ))}
            </div>
        </section>
    );
}

function LocationLink({
    url,
    appearance,
}: {
    url: string | undefined;
    appearance?: ConfirmationBlockAppearance;
}) {
    const locationUrl = safeUrl(url);

    if (!locationUrl) {
        return null;
    }

    return (
        <a
            href={locationUrl}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex w-fit items-center gap-2 rounded-md px-3 py-2 text-sm font-medium hover:opacity-90"
            style={{
                backgroundColor: safeColor(appearance?.buttonColor, '#171717'),
                color: safeColor(appearance?.buttonTextColor, '#ffffff'),
            }}
        >
            View location
            <ExternalLink className="size-4" aria-hidden="true" />
        </a>
    );
}

function LocationFieldRow({ field }: { field: LocationField }) {
    return (
        <div className="grid gap-0.5">
            <dt className="opacity-65">{field.label}</dt>
            <dd className="break-words">{formatValue(field.value)}</dd>
        </div>
    );
}

function hasUsefulContent(location: LocationItem): boolean {
    return Object.values(location).some((value) => {
        if (Array.isArray(value)) {
            return value.length > 0;
        }

        return value !== undefined && value !== '';
    });
}

function hasCoordinates(location: LocationItem): boolean {
    return (
        typeof location.latitude === 'number' &&
        Number.isFinite(location.latitude) &&
        typeof location.longitude === 'number' &&
        Number.isFinite(location.longitude)
    );
}

function formatAddress(location: LocationItem): string | null {
    const address = location.address?.trim() ?? '';
    const existing = address.toLocaleLowerCase();
    const supplemental = [
        location.city,
        location.region,
        location.postal_code,
        location.country,
    ].filter((part): part is string => {
        const normalizedPart = part?.trim();

        return (
            normalizedPart !== undefined &&
            normalizedPart !== '' &&
            !existing.includes(normalizedPart.toLocaleLowerCase())
        );
    });
    const parts = [address, ...supplemental].filter(Boolean);

    return parts.length > 0 ? parts.join(', ') : null;
}

function formatDistance(location: LocationItem): string | null {
    if (location.distance === undefined || location.distance === '') {
        return null;
    }

    const distance =
        typeof location.distance === 'number'
            ? new Intl.NumberFormat(undefined, {
                  maximumFractionDigits: 1,
              }).format(location.distance)
            : location.distance;

    return location.distance_unit
        ? `${distance} ${location.distance_unit}`
        : distance;
}

function formatValue(value: string | number | boolean): string {
    if (typeof value === 'boolean') {
        return value ? 'Yes' : 'No';
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
