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
import { destroy } from '@/routes/datasets/fields';
import type { DatasetField } from '@/types';

type Props = {
    datasetId: number;
    datasetSlug: string;
    field: DatasetField | null;
    currentTeamSlug: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export default function DeleteDatasetFieldDialog({
    datasetId,
    datasetSlug,
    field,
    currentTeamSlug,
    open,
    onOpenChange,
}: Props) {
    if (!field) {
        return null;
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <Form
                    {...destroy.form([currentTeamSlug, datasetId, field.id])}
                    onSuccess={() => onOpenChange(false)}
                >
                    {({ processing }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>Delete field mapping?</DialogTitle>
                                <DialogDescription>
                                    Remove <strong>"{field.label}"</strong> (
                                    {field.key}) from {datasetSlug}?
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
                                        : 'Delete field'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
