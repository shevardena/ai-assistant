import { useState } from 'react';
import type { ConfirmationBlock as ConfirmationBlockData } from '@/types';

export type ConfirmationAction = 'confirm' | 'cancel';

export type ConfirmationBlockAction = (
    actionReference: string,
    action: ConfirmationAction,
) => Promise<ConfirmationBlockData>;

export type ConfirmationBlockAppearance = {
    backgroundColor?: string;
    textColor?: string;
    buttonColor?: string;
    buttonTextColor?: string;
};

export function ConfirmationBlock({
    block,
    onAction,
    appearance,
}: {
    block: ConfirmationBlockData;
    onAction?: ConfirmationBlockAction;
    appearance?: ConfirmationBlockAppearance;
}) {
    const [currentBlock, setCurrentBlock] = useState(block);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const pending = currentBlock.data.status === 'pending';
    const interactive = pending && onAction !== undefined;

    async function handleAction(action: ConfirmationAction) {
        if (!interactive || processing) {
            return;
        }

        setProcessing(true);
        setError(null);

        try {
            const nextBlock = await onAction(
                currentBlock.data.action_reference,
                action,
            );
            setCurrentBlock(nextBlock);
        } catch {
            setCurrentBlock((current) => ({
                ...current,
                data: { ...current.data, status: 'failed' },
            }));
            setError('Could not update this action.');
        } finally {
            setProcessing(false);
        }
    }

    const status = processing
        ? 'Processing…'
        : statusLabel(currentBlock.data.status);

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
            aria-live="polite"
            aria-label="Action confirmation"
        >
            <p className="font-medium">{currentBlock.data.summary}</p>
            <p className="text-xs opacity-70">{status}</p>
            {pending ? (
                <div className="flex flex-wrap gap-2">
                    <button
                        type="button"
                        className="rounded-md border px-3 py-2 text-xs font-medium disabled:cursor-not-allowed disabled:opacity-50"
                        onClick={() => void handleAction('cancel')}
                        disabled={!interactive || processing}
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        className="rounded-md px-3 py-2 text-xs font-medium disabled:cursor-not-allowed disabled:opacity-50"
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
                        onClick={() => void handleAction('confirm')}
                        disabled={!interactive || processing}
                    >
                        {processing ? 'Processing…' : 'Confirm'}
                    </button>
                </div>
            ) : null}
            {error ? (
                <p className="text-xs text-red-700" role="alert">
                    {error}
                </p>
            ) : null}
        </section>
    );
}

function statusLabel(status: ConfirmationBlockData['data']['status']): string {
    switch (status) {
        case 'pending':
            return 'Awaiting confirmation';
        case 'confirmed':
            return 'Processing…';
        case 'completed':
            return 'Completed';
        case 'cancelled':
            return 'Cancelled';
        case 'failed':
            return 'Could not complete this action.';
    }
}

function safeColor(value: string | undefined, fallback: string): string {
    return value && /^#[0-9a-f]{6}$/i.test(value) ? value : fallback;
}
