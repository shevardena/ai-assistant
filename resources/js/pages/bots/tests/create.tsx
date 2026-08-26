import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import BotTestForm from '@/components/bot-test-form';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { index, store } from '@/routes/bots/tests';
import type { BotTestFormPageProps } from '@/types';

export default function BotTestsCreate({
    bot,
    tools,
    blocks,
}: BotTestFormPageProps) {
    const { currentTeam } = usePage().props;

    if (!currentTeam) {
        return null;
    }

    const botRoute = [currentTeam.slug, bot.id] as [string, number];
    const testsIndex = index(botRoute).url;

    return (
        <>
            <Head title={`New test · ${bot.name}`} />
            <div className="max-w-3xl space-y-6 p-4 md:p-6">
                <div className="flex items-center gap-3">
                    <Button variant="ghost" size="icon" asChild>
                        <Link href={testsIndex} aria-label="Back to Bot tests">
                            <ArrowLeft />
                        </Link>
                    </Button>
                    <Heading
                        variant="small"
                        title="New Bot test"
                        description={`Create a regression scenario for ${bot.name}.`}
                    />
                </div>
                <BotTestForm
                    action={store.form(botRoute)}
                    cancelHref={testsIndex}
                    submitLabel="Create test"
                    tools={tools}
                    blocks={blocks}
                />
            </div>
        </>
    );
}
