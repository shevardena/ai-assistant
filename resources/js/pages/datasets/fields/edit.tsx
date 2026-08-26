import { Head, usePage } from '@inertiajs/react';
import DatasetFieldForm from '@/components/dataset-field-form';
import Heading from '@/components/heading';
import { update } from '@/routes/datasets/fields';
import type { DatasetField } from '@/types';

type Props = {
    dataset: {
        id: number;
        name: string;
    };
    field: DatasetField;
};

export default function DatasetFieldsEdit({ dataset, field }: Props) {
    const { currentTeam } = usePage().props;

    if (!currentTeam) {
        return null;
    }

    return (
        <>
            <Head title={`Edit ${field.label}`} />
            <h1 className="sr-only">Edit {field.label}</h1>
            <div className="max-w-3xl p-4 md:p-6">
                <Heading
                    variant="small"
                    title={`Edit ${field.label}`}
                    description={`Update the field mapping for ${dataset.name}.`}
                />
                <DatasetFieldForm
                    action={update.form([
                        currentTeam.slug,
                        dataset.id,
                        field.id,
                    ])}
                    dataset={dataset}
                    field={field}
                    currentTeamSlug={currentTeam.slug}
                    submitLabel="Save changes"
                />
            </div>
        </>
    );
}
