import { Head, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import BotForm from '@/components/bot-form';
import Heading from '@/components/heading';
import { store } from '@/routes/bots';

export default function BotsCreate() {
    const { t } = useTranslation();
    const { currentTeam } = usePage().props;

    if (!currentTeam) {
        return null;
    }

    return (
        <>
            <Head title={t('common.create_bot')} />

            <h1 className="sr-only">{t('common.create_bot')}</h1>

            <div className="max-w-3xl p-4 md:p-6">
                <Heading
                    variant="small"
                    title={t('common.create_bot')}
                    description="Set up the bot identity. Integrations and advanced configuration can be added later."
                />

                <BotForm
                    action={store.form(currentTeam.slug)}
                    currentTeamSlug={currentTeam.slug}
                    submitLabel={t('common.create_bot')}
                />
            </div>
        </>
    );
}
