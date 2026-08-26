import { Head, Link, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/billing';

export default function BillingSuccess({ team }: { team: { name: string } }) {
    const { t } = useTranslation();
    const { currentTeam } = usePage().props;

    if (!currentTeam) {
        return null;
    }

    return (
        <>
            <Head title={t('billing.title')} />
            <div className="max-w-2xl p-4 md:p-6">
                <Heading
                    variant="small"
                    title="Payment processing"
                    description={`Stripe is confirming the billing update for ${team.name}. Your Team plan will change after the provider webhook is received.`}
                />
                <Button asChild className="mt-6">
                    <Link href={index(currentTeam.slug).url}>
                        Return to billing
                    </Link>
                </Button>
            </div>
        </>
    );
}

BillingSuccess.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Billing',
            href: props.currentTeam
                ? `/${props.currentTeam.slug}/billing`
                : '/',
        },
    ],
});
