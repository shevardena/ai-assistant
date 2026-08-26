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
import { destroy } from '@/routes/data-sources/files';
import type { SourceFile } from '@/types';

type Props = {
    currentTeamSlug: string;
    dataSourceId: number;
    sourceFile: SourceFile | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export default function DeleteSourceFileDialog({
    currentTeamSlug,
    dataSourceId,
    sourceFile,
    open,
    onOpenChange,
}: Props) {
    if (!sourceFile) {
        return null;
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <Form
                    {...destroy.form([
                        currentTeamSlug,
                        dataSourceId,
                        sourceFile.id,
                    ])}
                    onSuccess={() => onOpenChange(false)}
                >
                    {({ processing }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>Delete source file?</DialogTitle>
                                <DialogDescription>
                                    This permanently removes{' '}
                                    <strong>"{sourceFile.originalName}"</strong>{' '}
                                    and its stored file.
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
                                    {processing ? 'Deleting...' : 'Delete file'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
