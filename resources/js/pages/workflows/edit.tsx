import { Head, usePage } from '@inertiajs/react';
import Heading from '@/components/heading';
import WorkflowForm from '@/components/workflow-form';
import { update } from '@/routes/workflows';
import type { Workflow, WorkflowMetadata } from '@/types';

type Props = { workflow: Workflow; metadata: WorkflowMetadata };

export default function WorkflowsEdit({ workflow, metadata }: Props) {
    const { currentTeam } = usePage().props;

    if (!currentTeam) {
        return null;
    }

    return (
        <>
            <Head title={`Edit ${workflow.name}`} />
            <div className="max-w-4xl p-4 md:p-6">
                <Heading
                    variant="small"
                    title={`Edit ${workflow.name}`}
                    description="Update the structured trigger, conditions, or actions."
                />
                <div className="mt-6">
                    <WorkflowForm
                        action={update.form([
                            currentTeam.slug,
                            workflow.publicId,
                        ])}
                        currentTeamSlug={currentTeam.slug}
                        metadata={metadata}
                        workflow={workflow}
                        submitLabel="Save workflow"
                    />
                </div>
            </div>
        </>
    );
}
