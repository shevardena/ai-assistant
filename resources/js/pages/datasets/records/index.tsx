import { Form, Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, Eye, Plus, Search } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { index as datasetsIndex } from '@/routes/datasets';
import { index, create, show } from '@/routes/datasets/records';
import type {
    DatasetRecord,
    DatasetRecordFieldDefinition,
    DatasetSummary,
    Paginated,
} from '@/types';

type Props = {
    dataset: DatasetSummary;
    fields: DatasetRecordFieldDefinition[];
    records: Paginated<DatasetRecord>;
    filters: { search: string; status: string; origin: string };
    counts: { total: number; active: number };
};

function formatDate(value: string | null): string {
    return value
        ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(
              new Date(value),
          )
        : '—';
}

function originLabel(
    origin: DatasetRecord['origin'],
    t: (key: string) => string,
): string {
    return origin === 'manual'
        ? t('common.manual')
        : origin === 'file_import'
          ? t('common.file_import')
          : origin === 'graphql_api'
            ? t('common.graphql_api')
            : t('common.rest_api');
}

function isImageField(field: DatasetRecordFieldDefinition): boolean {
    const searchableName =
        `${field.key} ${field.label} ${field.config?.format ?? ''}`.toLowerCase();

    return (
        field.dataType === 'url' &&
        /(image|photo|picture|thumbnail|avatar|logo|icon)/.test(searchableName)
    );
}

function imageUrl(value: unknown): string | null {
    if (typeof value !== 'string') {
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

function formatValue(value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (typeof value === 'object') {
        return JSON.stringify(value);
    }

    return String(value);
}

export default function DatasetRecordsIndex({
    dataset,
    fields,
    records,
    filters,
    counts,
}: Props) {
    const { t } = useTranslation();
    const { currentTeam } = usePage().props;
    const displayFields = [
        ...(fields.some((field) => field.isDisplayable)
            ? fields.filter((field) => field.isDisplayable)
            : fields),
    ]
        .filter((field) => field.key.toLowerCase() !== 'id')
        .sort((firstField, secondField) => {
            const firstIsImage = isImageField(firstField) ? 0 : 1;
            const secondIsImage = isImageField(secondField) ? 0 : 1;

            return firstIsImage - secondIsImage;
        });

    if (!currentTeam) {
        return null;
    }

    return (
        <>
            <Head title={`${t('common.records')} · ${dataset.name}`} />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                    <div className="flex items-start gap-3">
                        <Button variant="ghost" size="icon" asChild>
                            <Link href={datasetsIndex(currentTeam.slug).url}>
                                <ArrowLeft />
                            </Link>
                        </Button>
                        <Heading
                            variant="small"
                            title={`${dataset.name} · ${t('common.records')}`}
                            description={`${counts.total} ${t('common.total_records')} · ${counts.active} ${t('common.active_records')}`}
                        />
                    </div>
                    <Button asChild>
                        <Link href={create([currentTeam.slug, dataset.id]).url}>
                            <Plus />
                            {t('common.add_record')}
                        </Link>
                    </Button>
                </div>

                <Form
                    {...index.form([currentTeam.slug, dataset.id], {
                        query: {
                            search: filters.search,
                            status: filters.status,
                            origin: filters.origin,
                        },
                    })}
                    className="flex flex-col gap-3 rounded-xl border p-4 md:flex-row md:items-end"
                >
                    <div className="grid flex-1 gap-2">
                        <label
                            htmlFor="record-search"
                            className="text-sm font-medium"
                        >
                            {t('common.search')}
                        </label>
                        <div className="relative">
                            <Search className="absolute top-2.5 left-3 size-4 text-muted-foreground" />
                            <Input
                                id="record-search"
                                name="search"
                                defaultValue={filters.search}
                                className="pl-9"
                                placeholder={t('common.search_records')}
                            />
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <label
                            htmlFor="record-status"
                            className="text-sm font-medium"
                        >
                            {t('common.status')}
                        </label>
                        <select
                            id="record-status"
                            name="status"
                            defaultValue={filters.status}
                            className="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                        >
                            <option value="">{t('common.all')}</option>
                            <option value="active">{t('status.active')}</option>
                            <option value="inactive">
                                {t('status.inactive')}
                            </option>
                        </select>
                    </div>
                    <div className="grid gap-2">
                        <label
                            htmlFor="record-origin"
                            className="text-sm font-medium"
                        >
                            {t('common.source')}
                        </label>
                        <select
                            id="record-origin"
                            name="origin"
                            defaultValue={filters.origin}
                            className="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                        >
                            <option value="">{t('common.all')}</option>
                            <option value="manual">{t('common.manual')}</option>
                            <option value="file_import">
                                {t('common.file_import')}
                            </option>
                            <option value="rest_api">
                                {t('common.rest_api')}
                            </option>
                            <option value="graphql_api">
                                {t('common.graphql_api')}
                            </option>
                        </select>
                    </div>
                    <Button type="submit">{t('common.filter')}</Button>
                </Form>

                {records.data.length > 0 ? (
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full min-w-max border-collapse text-sm">
                            <thead className="bg-muted/40 text-left text-muted-foreground">
                                <tr className="border-b">
                                    <th className="px-4 py-3 font-medium">
                                        {t('common.identifier')}
                                    </th>
                                    {displayFields.map((field) => (
                                        <th
                                            key={field.key}
                                            className="px-4 py-3 font-medium"
                                        >
                                            {field.label}
                                        </th>
                                    ))}
                                    <th className="px-4 py-3 font-medium">
                                        {t('common.source')}
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        {t('common.status')}
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        {t('common.updated')}
                                    </th>
                                    <th className="px-4 py-3 font-medium">
                                        {t('common.actions')}
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {records.data.map((record) => (
                                    <tr
                                        key={record.id}
                                        className="align-middle hover:bg-muted/20"
                                    >
                                        <td className="px-4 py-4">
                                            <Link
                                                className="font-medium hover:underline"
                                                href={
                                                    show([
                                                        currentTeam.slug,
                                                        dataset.id,
                                                        record.id,
                                                    ]).url
                                                }
                                            >
                                                {record.externalId}
                                            </Link>
                                        </td>
                                        {displayFields.map((field) => {
                                            const value =
                                                record.values[field.key]?.value;
                                            const url = imageUrl(value);

                                            return (
                                                <td
                                                    key={field.key}
                                                    className="max-w-64 px-4 py-4"
                                                >
                                                    {isImageField(field) &&
                                                    url ? (
                                                        <img
                                                            src={url}
                                                            alt={field.label}
                                                            className="h-14 w-20 rounded-md border object-cover"
                                                            loading="lazy"
                                                        />
                                                    ) : field.dataType ===
                                                          'url' && url ? (
                                                        <a
                                                            href={url}
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            className="block max-w-64 truncate text-primary hover:underline"
                                                        >
                                                            {formatValue(value)}
                                                        </a>
                                                    ) : (
                                                        <span
                                                            className="block max-w-64 truncate"
                                                            title={formatValue(
                                                                value,
                                                            )}
                                                        >
                                                            {formatValue(value)}
                                                        </span>
                                                    )}
                                                </td>
                                            );
                                        })}
                                        <td className="px-4 py-4">
                                            {originLabel(record.origin, t)}
                                        </td>
                                        <td className="px-4 py-4">
                                            <Badge
                                                variant={
                                                    record.isActive
                                                        ? 'default'
                                                        : 'secondary'
                                                }
                                            >
                                                {record.isActive
                                                    ? t('status.active')
                                                    : t('status.inactive')}
                                            </Badge>
                                        </td>
                                        <td className="px-4 py-4 whitespace-nowrap text-muted-foreground">
                                            {formatDate(record.updatedAt)}
                                        </td>
                                        <td className="px-4 py-4">
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
                                                            record.id,
                                                        ]).url
                                                    }
                                                    aria-label={t(
                                                        'common.view',
                                                    )}
                                                >
                                                    <Eye />
                                                </Link>
                                            </Button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <div className="rounded-xl border border-dashed px-6 py-16 text-center text-sm text-muted-foreground">
                        {t('common.no_records')}
                    </div>
                )}

                {records.last_page > 1 ? (
                    <nav
                        className="flex flex-wrap justify-center gap-2"
                        aria-label={t('common.pagination')}
                    >
                        {records.links.map((link, index) =>
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
                                        {link.label
                                            .replace(
                                                '&laquo;',
                                                t('common.previous_page'),
                                            )
                                            .replace(
                                                '&raquo;',
                                                t('common.next_page'),
                                            )}
                                    </Link>
                                </Button>
                            ) : null,
                        )}
                    </nav>
                ) : null}
            </div>
        </>
    );
}
