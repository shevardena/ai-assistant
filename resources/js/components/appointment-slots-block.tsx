import { useMemo, useState } from 'react';
import type { ConfirmationBlockAppearance } from '@/components/confirmation-block';
import type {
    AppointmentSlot,
    AppointmentSlotsBlock as AppointmentSlotsBlockData,
    ConversationBlock,
} from '@/types';

export type AppointmentSelectionResult = {
    block: AppointmentSlotsBlockData;
    user_message?: {
        role: 'user';
        content: string;
        blocks?: ConversationBlock[];
    };
    message?: {
        role: 'assistant';
        content: string;
        blocks?: ConversationBlock[];
        cards?: unknown[];
    };
};

export type AppointmentSlotsAction = (
    appointmentReference: string,
    slotReference: string,
) => Promise<AppointmentSelectionResult>;

export class AppointmentSelectionError extends Error {
    constructor(
        message: string,
        public readonly block?: AppointmentSlotsBlockData,
    ) {
        super(message);
        this.name = 'AppointmentSelectionError';
    }
}

export function AppointmentSlotsBlock({
    block,
    onSelect,
    appearance,
}: {
    block: AppointmentSlotsBlockData;
    onSelect?: AppointmentSlotsAction;
    appearance?: ConfirmationBlockAppearance;
}) {
    const [currentBlock, setCurrentBlock] = useState(block);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const groups = useMemo(
        () => groupSlots(currentBlock.data.slots, currentBlock.data.timezone),
        [currentBlock],
    );
    const interactive =
        currentBlock.data.status === 'pending' &&
        onSelect !== undefined &&
        !processing;

    async function select(slot: AppointmentSlot) {
        if (!interactive || onSelect === undefined) {
            return;
        }

        setProcessing(true);
        setError(null);

        try {
            const result = await onSelect(
                currentBlock.data.appointment_reference,
                slot.slot_reference,
            );
            setCurrentBlock(result.block);
        } catch (exception) {
            if (exception instanceof AppointmentSelectionError) {
                if (exception.block) {
                    setCurrentBlock(exception.block);
                }

                setError(exception.message);
            } else {
                setError(
                    'Could not select this appointment time. Please try again.',
                );
            }
        } finally {
            setProcessing(false);
        }
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
            aria-label="Available appointment times"
            aria-live="polite"
        >
            {currentBlock.data.title ? (
                <h3 className="font-medium">{currentBlock.data.title}</h3>
            ) : null}
            <p className="text-xs opacity-70">
                Times shown in {currentBlock.data.timezone}.
            </p>
            {groups.length > 0 ? (
                <div className="grid gap-3">
                    {groups.map((group) => (
                        <fieldset key={group.key} className="grid gap-2">
                            <legend className="text-xs font-medium">
                                {group.label}
                            </legend>
                            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                {group.slots.map((slot) => {
                                    const selected =
                                        currentBlock.data
                                            .selected_slot_reference ===
                                        slot.slot_reference;

                                    return (
                                        <button
                                            key={slot.slot_reference}
                                            type="button"
                                            className="rounded-md border px-3 py-2 text-left text-xs transition outline-none focus-visible:ring-2 disabled:cursor-not-allowed disabled:opacity-50"
                                            style={{
                                                backgroundColor: selected
                                                    ? safeColor(
                                                          appearance?.buttonColor,
                                                          '#171717',
                                                      )
                                                    : 'transparent',
                                                color: selected
                                                    ? safeColor(
                                                          appearance?.buttonTextColor,
                                                          '#ffffff',
                                                      )
                                                    : 'inherit',
                                            }}
                                            disabled={!interactive}
                                            aria-pressed={selected}
                                            onClick={() => void select(slot)}
                                        >
                                            <span className="block font-medium">
                                                {slot.label ||
                                                    formatTime(
                                                        slot.starts_at,
                                                        currentBlock.data
                                                            .timezone,
                                                    )}
                                            </span>
                                            {slot.ends_at ? (
                                                <span className="block opacity-70">
                                                    Until{' '}
                                                    {formatTime(
                                                        slot.ends_at,
                                                        currentBlock.data
                                                            .timezone,
                                                    )}
                                                </span>
                                            ) : null}
                                            {selected ? 'Selected' : null}
                                        </button>
                                    );
                                })}
                            </div>
                        </fieldset>
                    ))}
                </div>
            ) : (
                <p className="text-xs opacity-70">
                    No appointment times are available.
                </p>
            )}
            {error ? (
                <p className="text-xs text-red-600" role="alert">
                    {error}
                </p>
            ) : null}
            {currentBlock.data.status !== 'pending' ? (
                <p className="text-xs opacity-70">
                    {currentBlock.data.status === 'selected'
                        ? 'Appointment time selected.'
                        : currentBlock.data.status === 'expired'
                          ? 'These appointment times have expired.'
                          : 'These appointment times are no longer available.'}
                </p>
            ) : null}
        </section>
    );
}

function groupSlots(slots: AppointmentSlot[], timezone: string) {
    const groups = new Map<
        string,
        { key: string; label: string; slots: AppointmentSlot[] }
    >();

    slots.forEach((slot) => {
        const date = new Intl.DateTimeFormat('en-CA', {
            timeZone: timezone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
        }).format(new Date(slot.starts_at));
        const label = new Intl.DateTimeFormat(undefined, {
            timeZone: timezone,
            weekday: 'long',
            month: 'short',
            day: 'numeric',
        }).format(new Date(slot.starts_at));
        const group = groups.get(date) ?? { key: date, label, slots: [] };
        group.slots.push(slot);
        groups.set(date, group);
    });

    return [...groups.values()];
}

function formatTime(value: string, timezone: string): string {
    return new Intl.DateTimeFormat(undefined, {
        timeZone: timezone,
        hour: 'numeric',
        minute: '2-digit',
    }).format(new Date(value));
}

function safeColor(value: string | undefined, fallback: string): string {
    return value && /^#[0-9a-f]{3,8}$/i.test(value) ? value : fallback;
}
