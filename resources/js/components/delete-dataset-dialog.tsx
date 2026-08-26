import { Form } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { destroy } from '@/routes/datasets';
import type { DatasetSummary } from '@/types';

type Props = {
    dataset: DatasetSummary | null;
    currentTeamSlug: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export default function DeleteDatasetDialog({
    dataset,
    currentTeamSlug,
    open,
    onOpenChange,
}: Props) {
    if (!dataset) {
        return null;
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <Form
                    {...destroy.form([currentTeamSlug, dataset.id])}
                    onSuccess={() => onOpenChange(false)}
                >
                    {({ processing }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>Delete dataset?</DialogTitle>
                                <DialogDescription>
                                    This will remove{' '}
                                    <strong>"{dataset.name}"</strong> from the
                                    current team. Dataset records and field
                                    mappings are not force-deleted from the
                                    dashboard.
                                </DialogDescription>
                            </DialogHeader>
                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>
                                <Button
                                    variant="destructive"
                                    type="submit"
                                    disabled={processing}
                                >
                                    {processing
                                        ? 'Deleting...'
                                        : 'Delete dataset'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
