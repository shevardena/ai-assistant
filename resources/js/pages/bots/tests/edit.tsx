import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import BotTestForm from '@/components/bot-test-form';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { show, update } from '@/routes/bots/tests';
import type { BotTestDetailPageProps } from '@/types';

export default function BotTestsEdit({
    bot,
    scenario,
    tools,
    blocks,
}: BotTestDetailPageProps) {
    const { currentTeam } = usePage().props;

    if (!currentTeam) {
        return null;
    }

    const route = [currentTeam.slug, bot.id, scenario.publicId] as [
        string,
        number,
        string,
    ];

    return (
        <>
            <Head title={`Edit ${scenario.name}`} />
            <div className="max-w-3xl space-y-6 p-4 md:p-6">
                <div className="flex items-center gap-3">
                    <Button variant="ghost" size="icon" asChild>
                        <Link href={show(route).url} aria-label="Back to test">
                            <ArrowLeft />
                        </Link>
                    </Button>
                    <Heading
                        variant="small"
                        title={`Edit ${scenario.name}`}
                        description="Update the message or deterministic expectations."
                    />
                </div>
                <BotTestForm
                    action={update.form(route)}
                    cancelHref={show(route).url}
                    submitLabel="Save changes"
                    tools={tools}
                    blocks={blocks}
                    scenario={scenario}
                />
            </div>
        </>
    );
}
