import { Head, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Heading from '@/components/heading';
import WorkflowForm from '@/components/workflow-form';
import { store } from '@/routes/workflows';
import type { WorkflowMetadata } from '@/types';

export default function WorkflowsCreate({
    metadata,
}: {
    metadata: WorkflowMetadata;
}) {
    const { t } = useTranslation();
    const { currentTeam } = usePage().props;

    if (!currentTeam) {
        return null;
    }

    return (
        <>
            <Head title={t('common.create_workflow')} />
            <div className="max-w-4xl p-4 md:p-6">
                <Heading
                    variant="small"
                    title={t('common.create_workflow')}
                    description="Build a deterministic internal automation for your current Team."
                />
                <div className="mt-6">
                    <WorkflowForm
                        action={store.form(currentTeam.slug)}
                        currentTeamSlug={currentTeam.slug}
                        metadata={metadata}
                        submitLabel="Save draft"
                    />
                </div>
            </div>
        </>
    );
}
