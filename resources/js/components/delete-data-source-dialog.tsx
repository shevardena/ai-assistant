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
import { destroy } from '@/routes/data-sources';
import type { DataSourceSummary } from '@/types';

type Props = {
    dataSource: DataSourceSummary | null;
    currentTeamSlug: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export default function DeleteDataSourceDialog({
    dataSource,
    currentTeamSlug,
    open,
    onOpenChange,
}: Props) {
    if (!dataSource) {
        return null;
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <Form
                    {...destroy.form([currentTeamSlug, dataSource.id])}
                    onSuccess={() => onOpenChange(false)}
                >
                    {({ processing }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>Delete data source?</DialogTitle>
                                <DialogDescription>
                                    This will remove{' '}
                                    <strong>"{dataSource.name}"</strong> from
                                    the current team. You can’t undo this action
                                    from the dashboard.
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
                                    data-test="data-source-delete-confirm"
                                >
                                    {processing
                                        ? 'Deleting...'
                                        : 'Delete data source'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
