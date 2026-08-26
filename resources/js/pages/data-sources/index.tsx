import { Head, Link, usePage } from '@inertiajs/react';
import { Database, Eye, FileText, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import DeleteDataSourceDialog from '@/components/delete-data-source-dialog';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { dataSourceStatusVariant, statusLabel } from '@/lib/status';
import { create, edit, show } from '@/routes/data-sources';
import { create as createApi } from '@/routes/data-sources/api';
import { create as createGraphql } from '@/routes/data-sources/graphql';
import type { DataSourceSummary, DataSourceType, Paginated } from '@/types';

type Props = {
    dataSources: Paginated<DataSourceSummary>;
};

function typeLabel(
    type: DataSourceType,
    translate: (key: string) => string,
): string {
    return type === 'rest_api'
        ? translate('common.rest_api')
        : type === 'graphql_api'
          ? translate('common.graphql_api')
          : translate('common.uploaded_file');
}

function typeIcon(type: DataSourceType) {
    return type === 'rest_api' || type === 'graphql_api' ? Database : FileText;
}

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

export default function DataSourcesIndex({ dataSources }: Props) {
    const { t } = useTranslation();
    const { currentTeam } = usePage().props;
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [dataSourceToDelete, setDataSourceToDelete] =
        useState<DataSourceSummary | null>(null);

    if (!currentTeam) {
        return null;
    }

    const openDeleteDialog = (dataSource: DataSourceSummary) => {
        setDataSourceToDelete(dataSource);
        setDeleteDialogOpen(true);
    };

    return (
        <>
            <Head title={t('navigation.data_sources')} />

            <h1 className="sr-only">{t('navigation.data_sources')}</h1>

            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                    <Heading
                        variant="small"
                        title={t('navigation.data_sources')}
                        description={t('common.manage_team_sources')}
                    />

                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" asChild>
                            <Link href={createApi(currentTeam.slug).url}>
                                <Database />
                                {t('api_builder.connection_title')}
                            </Link>
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href={createGraphql(currentTeam.slug).url}>
                                <Database />
                                {t('graphql_builder.connection_title')}
                            </Link>
                        </Button>
                        <Button asChild data-test="data-sources-create-button">
                            <Link href={create(currentTeam.slug).url}>
                                <Plus />
                                {t('common.create_data_source')}
                            </Link>
                        </Button>
                    </div>
                </div>

                {dataSources.data.length > 0 ? (
                    <div className="overflow-hidden rounded-xl border">
                        <div className="hidden grid-cols-[minmax(0,1fr)_9rem_8rem_10rem_7rem] gap-4 border-b bg-muted/40 px-4 py-3 text-sm font-medium text-muted-foreground md:grid">
                            <span>{t('common.data_source')}</span>
                            <span>{t('common.type')}</span>
                            <span>{t('common.status')}</span>
                            <span>{t('common.updated')}</span>
                            <span className="text-right">
                                {t('common.actions')}
                            </span>
                        </div>

                        <div className="divide-y">
                            {dataSources.data.map((dataSource) => {
                                const Icon = typeIcon(dataSource.type);

                                return (
                                    <div
                                        key={dataSource.id}
                                        className="grid gap-4 px-4 py-4 md:grid-cols-[minmax(0,1fr)_9rem_8rem_10rem_7rem] md:items-center"
                                        data-test="data-source-row"
                                    >
                                        <div className="flex min-w-0 items-center gap-3">
                                            <div className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                                <Icon className="size-5" />
                                            </div>
                                            <div className="min-w-0">
                                                <Link
                                                    href={
                                                        show([
                                                            currentTeam.slug,
                                                            dataSource.id,
                                                        ]).url
                                                    }
                                                    className="truncate font-medium hover:underline"
                                                >
                                                    {dataSource.name}
                                                </Link>
                                                <p className="truncate text-sm text-muted-foreground">
                                                    {typeLabel(
                                                        dataSource.type,
                                                        t,
                                                    )}
                                                </p>
                                            </div>
                                        </div>

                                        <p className="text-sm">
                                            {typeLabel(dataSource.type, t)}
                                        </p>

                                        <div>
                                            <Badge
                                                variant={dataSourceStatusVariant(
                                                    dataSource.status,
                                                )}
                                            >
                                                {statusLabel(dataSource.status)}
                                            </Badge>
                                        </div>

                                        <p className="text-sm text-muted-foreground">
                                            {formatDate(dataSource.updatedAt)}
                                        </p>

                                        <div className="flex items-center justify-start gap-1 md:justify-end">
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                asChild
                                            >
                                                <Link
                                                    href={
                                                        show([
                                                            currentTeam.slug,
                                                            dataSource.id,
                                                        ]).url
                                                    }
                                                    aria-label={`${t('common.view')} ${dataSource.name}`}
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
                                                            dataSource.id,
                                                        ]).url
                                                    }
                                                    aria-label={`${t('common.edit')} ${dataSource.name}`}
                                                >
                                                    <Pencil />
                                                </Link>
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                aria-label={`${t('common.delete')} ${dataSource.name}`}
                                                data-test="data-source-delete-button"
                                                onClick={() =>
                                                    openDeleteDialog(dataSource)
                                                }
                                            >
                                                <Trash2 />
                                            </Button>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                ) : (
                    <div className="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed px-6 py-16 text-center">
                        <div className="flex size-12 items-center justify-center rounded-full bg-primary/10 text-primary">
                            <Database className="size-6" />
                        </div>
                        <div className="space-y-1">
                            <h2 className="font-medium">
                                {t('common.no_data_sources')}
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                Connect your first source to start building
                                searchable data later.
                            </p>
                        </div>
                        <Button asChild>
                            <Link href={create(currentTeam.slug).url}>
                                <Plus />
                                {t('common.create_data_source')}
                            </Link>
                        </Button>
                    </div>
                )}

                {dataSources.last_page > 1 ? (
                    <nav
                        className="flex flex-wrap items-center justify-center gap-2"
                        aria-label={`${t('navigation.data_sources')} ${t('common.pagination')}`}
                    >
                        {dataSources.links.map((link, index) =>
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

            <DeleteDataSourceDialog
                dataSource={dataSourceToDelete}
                currentTeamSlug={currentTeam.slug}
                open={deleteDialogOpen}
                onOpenChange={setDeleteDialogOpen}
            />
        </>
    );
}
