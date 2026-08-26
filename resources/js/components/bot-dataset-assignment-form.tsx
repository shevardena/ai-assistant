import { Form, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { create as createDataset } from '@/routes/datasets';
import type { BotDatasetOption } from '@/types';
import type { RouteFormDefinition } from '@/wayfinder';

type Props = {
    action: RouteFormDefinition<'post'>;
    datasets: BotDatasetOption[];
    currentTeamSlug: string;
};

export default function BotDatasetAssignmentForm({
    action,
    datasets,
    currentTeamSlug,
}: Props) {
    return (
        <Form
            {...action}
            options={{ preserveScroll: true }}
            className="space-y-5"
        >
            {({ errors, processing }) => (
                <>
                    {datasets.length === 0 ? (
                        <div className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                            <p>No datasets available yet.</p>
                            <Link
                                href={createDataset(currentTeamSlug).url}
                                className="mt-2 inline-block text-primary underline-offset-4 hover:underline"
                            >
                                Create a dataset first.
                            </Link>
                        </div>
                    ) : (
                        <div className="space-y-3">
                            {datasets.map((dataset) => (
                                <Label
                                    key={dataset.id}
                                    className="flex cursor-pointer items-start gap-3 rounded-lg border p-3 font-normal transition-colors hover:bg-accent/50"
                                >
                                    <Checkbox
                                        name="datasets[]"
                                        value={String(dataset.id)}
                                        defaultChecked={dataset.attached}
                                        data-test={`bot-dataset-${dataset.id}`}
                                    />
                                    <span className="grid gap-1">
                                        <span className="font-medium">
                                            {dataset.name}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {dataset.slug}
                                        </span>
                                    </span>
                                </Label>
                            ))}
                        </div>
                    )}

                    <InputError message={errors.datasets} />

                    <Button type="submit" disabled={processing}>
                        {processing ? 'Saving...' : 'Save datasets'}
                    </Button>
                </>
            )}
        </Form>
    );
}
