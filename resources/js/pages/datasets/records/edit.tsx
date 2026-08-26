import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import DatasetRecordForm from '@/components/dataset-record-form';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { edit, index, update } from '@/routes/datasets/records';
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

export default function DatasetRecordEdit({ dataset, fields, record }: Props) {
    const { t } = useTranslation();
    const { currentTeam } = usePage().props;

    if (!currentTeam) {
        return null;
    }

    return (
        <div className="max-w-3xl p-4 md:p-6">
            <Head title={`${t('common.edit_record')} · ${dataset.name}`} />
            <div className="mb-6 flex items-center gap-3">
                <Button variant="ghost" size="icon" asChild>
                    <Link
                        href={
                            edit([currentTeam.slug, dataset.id, record.id]).url
                        }
                    >
                        <ArrowLeft />
                    </Link>
                </Button>
                <Heading
                    variant="small"
                    title={t('common.edit_record')}
                    description={record.externalId}
                />
            </div>
            {record.origin !== 'manual' ? (
                <div className="mb-6 rounded-lg border border-amber-300/60 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:bg-amber-950/20 dark:text-amber-100">
                    <p className="font-medium">{t('common.source_managed')}</p>
                    <p>{t('common.overwrite_warning')}</p>
                </div>
            ) : null}
            <DatasetRecordForm
                action={update.form([currentTeam.slug, dataset.id, record.id])}
                fields={fields}
                record={record}
                cancelUrl={index([currentTeam.slug, dataset.id]).url}
                submitLabel={t('common.save')}
            />
        </div>
    );
}
