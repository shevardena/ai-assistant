import { Head, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import DatasetForm from '@/components/dataset-form';
import Heading from '@/components/heading';
import { store } from '@/routes/datasets';
import type { DatasetDataSourceOption } from '@/types';

type Props = {
    dataSources: DatasetDataSourceOption[];
};

export default function DatasetsCreate({ dataSources }: Props) {
    const { t } = useTranslation();
    const { currentTeam } = usePage().props;

    if (!currentTeam) {
        return null;
    }

    return (
        <>
            <Head title={t('common.create_dataset')} />
            <h1 className="sr-only">{t('common.create_dataset')}</h1>
            <div className="max-w-3xl p-4 md:p-6">
                <Heading
                    variant="small"
                    title={t('common.create_dataset')}
                    description="Define the logical collection and connect it to a current-team data source."
                />
                <DatasetForm
                    action={store.form(currentTeam.slug)}
                    dataSources={dataSources}
                    currentTeamSlug={currentTeam.slug}
                    submitLabel={t('common.create_dataset')}
                />
            </div>
        </>
    );
}
