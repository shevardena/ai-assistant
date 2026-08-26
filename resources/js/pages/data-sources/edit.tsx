import { Head, usePage } from '@inertiajs/react';
import DataSourceForm from '@/components/data-source-form';
import Heading from '@/components/heading';
import { update } from '@/routes/data-sources';
import type { DataSource } from '@/types';

type Props = {
    dataSource: DataSource;
};

export default function DataSourcesEdit({ dataSource }: Props) {
    const { currentTeam } = usePage().props;

    if (!currentTeam) {
        return null;
    }

    return (
        <>
            <Head title={`Edit ${dataSource.name}`} />

            <h1 className="sr-only">Edit {dataSource.name}</h1>

            <div className="max-w-3xl p-4 md:p-6">
                <Heading
                    variant="small"
                    title={`Edit ${dataSource.name}`}
                    description="Update the source identity and non-secret configuration."
                />

                <DataSourceForm
                    action={update.form([currentTeam.slug, dataSource.id])}
                    dataSource={dataSource}
                    currentTeamSlug={currentTeam.slug}
                    sourceType={dataSource.type}
                    submitLabel="Save changes"
                />
            </div>
        </>
    );
}
