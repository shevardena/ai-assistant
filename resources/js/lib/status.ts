import { i18n } from '@/i18n';
import type { BotStatus, DataSourceStatus, DatasetStatus } from '@/types';

export type StatusBadgeVariant =
    'default' | 'secondary' | 'destructive' | 'outline';

const statusTranslationKeys: Record<string, string> = {
    indexing: 'status.processing',
    preparing: 'status.pending',
    syncing: 'status.processing',
};

export function statusLabel(status: string): string {
    const translationKey = statusTranslationKeys[status] ?? `status.${status}`;
    const translated = i18n.t(translationKey, { defaultValue: '' });

    if (translated !== '') {
        return translated;
    }

    return (
        status.charAt(0).toUpperCase() + status.slice(1).replaceAll('_', ' ')
    );
}

export function botStatusVariant(status: BotStatus): StatusBadgeVariant {
    return status === 'ready' || status === 'published'
        ? 'default'
        : status === 'disabled'
          ? 'outline'
          : 'secondary';
}

export function dataSourceStatusVariant(
    status: DataSourceStatus,
): StatusBadgeVariant {
    return status === 'ready'
        ? 'default'
        : status === 'error'
          ? 'destructive'
          : status === 'syncing' || status === 'disabled'
            ? 'outline'
            : 'secondary';
}

export function datasetStatusVariant(
    status: DatasetStatus,
): StatusBadgeVariant {
    return status === 'ready'
        ? 'default'
        : status === 'error'
          ? 'destructive'
          : status === 'processing' || status === 'indexing'
            ? 'outline'
            : 'secondary';
}

export function statusDescription(status: string): string | null {
    return (
        {
            draft: 'Add a ready Dataset to make this Bot usable.',
            ready: 'Ready to use.',
            pending: 'Waiting for a successful source import.',
            syncing: 'A source sync is currently running.',
            preparing: 'Waiting for a successful dataset import.',
            processing: 'A dataset import is currently running.',
            indexing: 'A dataset import is currently running.',
            error: 'The latest source or dataset operation failed.',
            disabled: 'This resource is disabled.',
        }[status] ?? null
    );
}
