import { Head, usePage } from '@inertiajs/react';
import BotDatasetAssignmentForm from '@/components/bot-dataset-assignment-form';
import BotForm from '@/components/bot-form';
import Heading from '@/components/heading';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { update } from '@/routes/bots';
import { update as updateDatasets } from '@/routes/bots/datasets';
import type { Bot } from '@/types';

type Props = {
    bot: Bot;
};

export default function BotsEdit({ bot }: Props) {
    const { currentTeam } = usePage().props;

    if (!currentTeam) {
        return null;
    }

    return (
        <>
            <Head title={`Edit ${bot.name}`} />

            <h1 className="sr-only">Edit {bot.name}</h1>

            <div className="max-w-3xl p-4 md:p-6">
                <Heading
                    variant="small"
                    title={`Edit ${bot.name}`}
                    description="Update the bot identity and response defaults."
                />

                <BotForm
                    action={update.form([currentTeam.slug, bot.id])}
                    bot={bot}
                    currentTeamSlug={currentTeam.slug}
                    submitLabel="Save changes"
                />

                <Card className="mt-8">
                    <CardHeader>
                        <CardTitle>Datasets</CardTitle>
                        <p className="text-sm text-muted-foreground">
                            Choose the current-team datasets this bot can use
                            for search.
                        </p>
                    </CardHeader>
                    <CardContent>
                        <BotDatasetAssignmentForm
                            action={updateDatasets.form([
                                currentTeam.slug,
                                bot.id,
                            ])}
                            datasets={bot.datasets}
                            currentTeamSlug={currentTeam.slug}
                        />
                    </CardContent>
                </Card>
            </div>
        </>
    );
}
