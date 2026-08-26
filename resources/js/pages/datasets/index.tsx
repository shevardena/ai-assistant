import { Head, Link, usePage } from '@inertiajs/react';
import { Database, Eye, Layers, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import DeleteDatasetDialog from '@/components/delete-dataset-dialog';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { datasetStatusVariant, statusLabel } from '@/lib/status';
import { create, edit, show } from '@/routes/datasets';
import { index as recordsIndex } from '@/routes/datasets/records';
import type { DatasetSummary, Paginated } from '@/types';

type Props = {
    datasets: Paginated<DatasetSummary>;
};

function formatDate(value: string | null): string {
    return value
        ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(
              new Date(value),
          )
        : '—';
}

function paginationLabel(
    label: string,
    translate: (key: string) => string,
): string {
    return label
        .replace('&laquo;', translate('common.previous_page'))
        .replace('&raquo;', translate('common.next_page'));
}

export default function DatasetsIndex({ datasets }: Props) {
    const { t } = useTranslation();
    const { currentTeam } = usePage().props;
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [datasetToDelete, setDatasetToDelete] =
        useState<DatasetSummary | null>(null);

    if (!currentTeam) {
        return null;
    }

    const openDeleteDialog = (dataset: DatasetSummary) => {
        setDatasetToDelete(dataset);
        setDeleteDialogOpen(true);
    };

    return (
        <>
            <Head title={t('navigation.datasets')} />
            <h1 className="sr-only">{t('navigation.datasets')}</h1>

            <div className="flex min-w-0 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                    <Heading
                        variant="small"
                        title={t('navigation.datasets')}
                        description={t('common.define_team_collections')}
                    />
                    <Button asChild data-test="datasets-create-button">
                        <Link href={create(currentTeam.slug).url}>
                            <Plus />
                            {t('common.create_dataset')}
                        </Link>
                    </Button>
                </div>

                {datasets.data.length > 0 ? (
                    <div className="min-w-0 overflow-hidden rounded-xl border">
                        <div className="hidden grid-cols-[minmax(0,1fr)_12rem_8rem_10rem_7rem] gap-8 border-b bg-muted/40 px-4 py-3 text-sm font-medium text-muted-foreground 2xl:grid">
                            <span>{t('common.dataset')}</span>
                            <span>{t('common.data_source')}</span>
                            <span>{t('common.status')}</span>
                            <span>{t('common.updated')}</span>
                            <span className="text-right">
                                {t('common.actions')}
                            </span>
                        </div>
                        <div className="divide-y">
                            {datasets.data.map((dataset) => (
                                <div
                                    key={dataset.id}
                                    className="grid gap-6 px-4 py-5 2xl:grid-cols-[minmax(0,1fr)_12rem_8rem_10rem_7rem] 2xl:items-center 2xl:gap-8 2xl:py-4"
                                    data-test="dataset-row"
                                >
                                    <div className="flex min-w-0 items-center gap-3">
                                        <div className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                            <Layers className="size-5" />
                                        </div>
                                        <div className="min-w-0">
                                            <Link
                                                href={
                                                    show([
                                                        currentTeam.slug,
                                                        dataset.id,
                                                    ]).url
                                                }
                                                className="truncate font-medium hover:underline"
                                            >
                                                {dataset.name}
                                            </Link>
                                            <p className="truncate text-sm text-muted-foreground">
                                                {dataset.slug} ·{' '}
                                                {dataset.entityType}
                                            </p>
                                        </div>
                                    </div>
                                    <p className="truncate text-sm">
                                        {dataset.dataSource?.name ??
                                            'No source'}
                                    </p>
                                    <Badge
                                        variant={datasetStatusVariant(
                                            dataset.status,
                                        )}
                                    >
                                        {statusLabel(dataset.status)}
                                    </Badge>
                                    <p className="text-sm text-muted-foreground">
                                        {formatDate(dataset.updatedAt)}
                                    </p>
                                    <div className="flex items-center justify-start gap-3 2xl:justify-end">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            asChild
                                        >
                                            <Link
                                                href={
                                                    recordsIndex([
                                                        currentTeam.slug,
                                                        dataset.id,
                                                    ]).url
                                                }
                                            >
                                                <Database />
                                                {t('common.records')}
                                            </Link>
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            asChild
                                        >
                                            <Link
                                                href={
                                                    show([
                                                        currentTeam.slug,
                                                        dataset.id,
                                                    ]).url
                                                }
                                                aria-label={`${t('common.view')} ${dataset.name}`}
                                            >
                                                <Eye />
                                            </Link>
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            asChild
                                        >
                                            <Link
                                                href={
                                                    edit([
                                                        currentTeam.slug,
                                                        dataset.id,
                                                    ]).url
                                                }
                                                aria-label={`${t('common.edit')} ${dataset.name}`}
                                            >
                                                <Pencil />
                                            </Link>
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            aria-label={`${t('common.delete')} ${dataset.name}`}
                                            onClick={() =>
                                                openDeleteDialog(dataset)
                                            }
                                        >
                                            <Trash2 />
                                        </Button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                ) : (
                    <div className="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed px-6 py-16 text-center">
                        <div className="flex size-12 items-center justify-center rounded-full bg-primary/10 text-primary">
                            <Layers className="size-6" />
                        </div>
                        <div className="space-y-1">
                            <h2 className="font-medium">
                                {t('common.no_datasets')}
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                Define your first searchable collection from a
                                team data source.
                            </p>
                        </div>
                        <Button asChild>
                            <Link href={create(currentTeam.slug).url}>
                                <Plus />
                                {t('common.create_dataset')}
                            </Link>
                        </Button>
                    </div>
                )}

                {datasets.last_page > 1 ? (
                    <nav
                        className="flex flex-wrap items-center justify-center gap-2"
                        aria-label={`${t('navigation.datasets')} ${t('common.pagination')}`}
                    >
                        {datasets.links.map((link, index) =>
                            link.url ? (
                                <Button
                                    key={`${link.label}-${index}`}
                                    variant={
                                        link.active ? 'default' : 'outline'
                                    }
                                    size="sm"
                                    asChild
                                >
                                    <Link href={link.url}>
                                        {paginationLabel(link.label, t)}
                                    </Link>
                                </Button>
                            ) : (
                                <Button
                                    key={`${link.label}-${index}`}
                                    variant="outline"
                                    size="sm"
                                    disabled
                                >
                                    {paginationLabel(link.label, t)}
                                </Button>
                            ),
                        )}
                    </nav>
                ) : null}
            </div>

            <DeleteDatasetDialog
                dataset={datasetToDelete}
                currentTeamSlug={currentTeam.slug}
                open={deleteDialogOpen}
                onOpenChange={setDeleteDialogOpen}
            />
        </>
    );
}
