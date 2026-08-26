import { Head, usePage } from '@inertiajs/react';
import DatasetForm from '@/components/dataset-form';
import Heading from '@/components/heading';
import { update } from '@/routes/datasets';
import type { Dataset, DatasetDataSourceOption } from '@/types';

type Props = {
    dataset: Dataset;
    dataSources: DatasetDataSourceOption[];
};

export default function DatasetsEdit({ dataset, dataSources }: Props) {
    const { currentTeam } = usePage().props;

    if (!currentTeam) {
        return null;
    }

    return (
        <>
            <Head title={`Edit ${dataset.name}`} />
            <h1 className="sr-only">Edit {dataset.name}</h1>
            <div className="max-w-3xl p-4 md:p-6">
                <Heading
                    variant="small"
                    title={`Edit ${dataset.name}`}
                    description="Update dataset configuration and its current-team source."
                />
                <DatasetForm
                    action={update.form([currentTeam.slug, dataset.id])}
                    dataset={dataset}
                    dataSources={dataSources}
                    currentTeamSlug={currentTeam.slug}
                    submitLabel="Save changes"
                />
            </div>
        </>
    );
}
