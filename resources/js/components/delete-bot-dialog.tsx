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
import { destroy } from '@/routes/bots';
import type { BotSummary } from '@/types';

type Props = {
    bot: BotSummary | null;
    currentTeamSlug: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export default function DeleteBotDialog({
    bot,
    currentTeamSlug,
    open,
    onOpenChange,
}: Props) {
    if (!bot) {
        return null;
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <Form
                    {...destroy.form([currentTeamSlug, bot.id])}
                    onSuccess={() => onOpenChange(false)}
                >
                    {({ processing }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>Delete bot?</DialogTitle>
                                <DialogDescription>
                                    This will remove{' '}
                                    <strong>"{bot.name}"</strong> from the
                                    current team. You can’t undo this action
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
                                    data-test="bot-delete-confirm"
                                >
                                    {processing ? 'Deleting...' : 'Delete bot'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
