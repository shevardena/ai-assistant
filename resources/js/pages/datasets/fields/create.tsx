import { Head, usePage } from '@inertiajs/react';
import DatasetFieldForm from '@/components/dataset-field-form';
import Heading from '@/components/heading';
import { store } from '@/routes/datasets/fields';

type Props = {
    dataset: {
        id: number;
        name: string;
    };
};

export default function DatasetFieldsCreate({ dataset }: Props) {
    const { currentTeam } = usePage().props;

    if (!currentTeam) {
        return null;
    }

    return (
        <>
            <Head title={`Add field to ${dataset.name}`} />
            <h1 className="sr-only">Add field to {dataset.name}</h1>
            <div className="max-w-3xl p-4 md:p-6">
                <Heading
                    variant="small"
                    title="Add field mapping"
                    description={`Configure a source field for ${dataset.name}.`}
                />
                <DatasetFieldForm
                    action={store.form([currentTeam.slug, dataset.id])}
                    dataset={dataset}
                    currentTeamSlug={currentTeam.slug}
                    submitLabel="Create field"
                />
            </div>
        </>
    );
}
