import { Form, Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, Pencil, RotateCcw, Trash2, X } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    activate,
    deactivate,
    destroy,
    edit,
    index,
} from '@/routes/datasets/records';
import type {
    DatasetRecord,
    DatasetRecordFieldDefinition,
    DatasetSummary,
} from '@/types';

type Props = {
    dataset: DatasetSummary;
    fields: DatasetRecordFieldDefinition[];
    record: DatasetRecord;
};

function formatDate(value: string | null): string {
    return value
        ? new Intl.DateTimeFormat(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(new Date(value))
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

export default function DatasetRecordShow({ dataset, fields, record }: Props) {
    const { t } = useTranslation();
    const { currentTeam } = usePage().props;

    if (!currentTeam) {
        return null;
    }

    return (
        <div className="flex flex-col gap-6 p-4 md:p-6">
            <Head title={`${record.externalId} · ${dataset.name}`} />
            <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                <div className="flex items-start gap-3">
                    <Button variant="ghost" size="icon" asChild>
                        <Link href={index([currentTeam.slug, dataset.id]).url}>
                            <ArrowLeft />
                        </Link>
                    </Button>
                    <Heading
                        variant="small"
                        title={record.externalId}
                        description={dataset.name}
                    />
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <Badge variant={record.isActive ? 'default' : 'secondary'}>
                        {record.isActive
                            ? t('status.active')
                            : t('status.inactive')}
                    </Badge>
                    <Button variant="outline" asChild>
                        <Link
                            href={
                                edit([currentTeam.slug, dataset.id, record.id])
                                    .url
                            }
                        >
                            <Pencil />
                            {t('common.edit_record')}
                        </Link>
                    </Button>
                    {record.isActive ? (
                        <Form
                            {...deactivate.form([
                                currentTeam.slug,
                                dataset.id,
                                record.id,
                            ])}
                        >
                            <Button variant="outline" type="submit">
                                <X />
                                {t('common.deactivate')}
                            </Button>
                        </Form>
                    ) : (
                        <Form
                            {...activate.form([
                                currentTeam.slug,
                                dataset.id,
                                record.id,
                            ])}
                        >
                            <Button variant="outline" type="submit">
                                <RotateCcw />
                                {t('common.activate')}
                            </Button>
                        </Form>
                    )}
                    {record.origin === 'manual' ? (
                        <Form
                            {...destroy.form([
                                currentTeam.slug,
                                dataset.id,
                                record.id,
                            ])}
                            onSubmit={(event) => {
                                if (!window.confirm(t('common.delete_confirm'))) {
                                    event.preventDefault();
                                }
                            }}
                        >
                            <Button variant="destructive" type="submit">
                                <Trash2 />
                                {t('common.delete')}
                            </Button>
                        </Form>
                    ) : null}
                </div>
            </div>

            {record.origin !== 'manual' ? (
                <div className="rounded-lg border border-amber-300/60 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:bg-amber-950/20 dark:text-amber-100">
                    <p className="font-medium">{t('common.source_managed')}</p>
                    <p>{t('common.overwrite_warning')}</p>
                </div>
            ) : null}

            <div className="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(16rem,1fr)]">
                <Card>
                    <CardHeader>
                        <CardTitle>{t('common.fields')}</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-5 sm:grid-cols-2">
                        {fields.map((field) => (
                            <div className="space-y-1" key={field.key}>
                                <p className="text-sm text-muted-foreground">
                                    {field.label}
                                </p>
                                <p className="font-medium break-words whitespace-pre-wrap">
                                    {String(
                                        record.values[field.key]?.value ?? '—',
                                    )}
                                </p>
                            </div>
                        ))}
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>{t('common.details')}</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-4 text-sm">
                        <div>
                            <p className="text-muted-foreground">
                                {t('common.source')}
                            </p>
                            <p className="font-medium">
                                {originLabel(record.origin, t)}
                            </p>
                        </div>
                        <div>
                            <p className="text-muted-foreground">
                                {t('common.identifier')}
                            </p>
                            <p className="font-medium break-all">
                                {record.externalId}
                            </p>
                        </div>
                        <div>
                            <p className="text-muted-foreground">
                                {t('common.created')}
                            </p>
                            <p>{formatDate(record.createdAt)}</p>
                        </div>
                        <div>
                            <p className="text-muted-foreground">
                                {t('common.updated')}
                            </p>
                            <p>{formatDate(record.updatedAt)}</p>
                        </div>
                        <div>
                            <p className="text-muted-foreground">
                                {t('common.source_updated')}
                            </p>
                            <p>{formatDate(record.sourceUpdatedAt)}</p>
                        </div>
                    </CardContent>
                </Card>
            </div>
            {record.raw ? (
                <details className="rounded-xl border p-4">
                    <summary className="cursor-pointer text-sm font-medium">
                        {t('common.raw_data')}
                    </summary>
                    <pre className="mt-4 overflow-auto text-xs">
                        {JSON.stringify(record.raw, null, 2)}
                    </pre>
                </details>
            ) : null}
        </div>
    );
}
