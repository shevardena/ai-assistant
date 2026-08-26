import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import DatasetRecordForm from '@/components/dataset-record-form';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { index, store } from '@/routes/datasets/records';
import type { DatasetRecordFieldDefinition, DatasetSummary } from '@/types';

type Props = {
    dataset: DatasetSummary;
    fields: DatasetRecordFieldDefinition[];
};

export default function DatasetRecordCreate({ dataset, fields }: Props) {
    const { t } = useTranslation();
    const { currentTeam } = usePage().props;

    if (!currentTeam) {
        return null;
    }

    return (
        <div className="max-w-3xl p-4 md:p-6">
            <Head title={`${t('common.add_record')} · ${dataset.name}`} />
            <div className="mb-6 flex items-center gap-3">
                <Button variant="ghost" size="icon" asChild>
                    <Link href={index([currentTeam.slug, dataset.id]).url}>
                        <ArrowLeft />
                    </Link>
                </Button>
                <Heading
                    variant="small"
                    title={t('common.add_record')}
                    description={dataset.name}
                />
            </div>
            <DatasetRecordForm
                action={store.form([currentTeam.slug, dataset.id])}
                fields={fields}
                cancelUrl={index([currentTeam.slug, dataset.id]).url}
                submitLabel={t('common.add_record')}
            />
        </div>
    );
}
