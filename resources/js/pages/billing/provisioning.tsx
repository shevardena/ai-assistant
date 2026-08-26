import { Form, Head, Link } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { edit as editTeam } from '@/routes/teams';
import workspaceProvisioning from '@/routes/workspace-provisioning';

type Props = {
    provisioning: {
        id: number;
        team_name: string;
        plan_name: string;
        status: 'pending' | 'checkout_created' | 'completed' | 'cancelled' | 'expired';
        team: { name: string; slug: string } | null;
    };
    notice?: string | null;
};

export default function Provisioning({ provisioning, notice }: Props) {
    const { t } = useTranslation();
    const completed = provisioning.status === 'completed';
    const stopped = provisioning.status === 'cancelled' || provisioning.status === 'expired';

    return (
        <>
            <Head title="Workspace provisioning" />
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-6 p-4 md:p-8">
                <Heading variant="small" title={completed ? t('billing.workspace_created') : stopped ? t('billing.payment_not_completed') : t('billing.workspace_activation_pending')} description={`${provisioning.team_name} · ${provisioning.plan_name}`} />
                <Card>
                    <CardHeader>
                        <CardTitle>{completed ? 'Your paid workspace is ready.' : stopped ? 'No workspace was created.' : 'We are waiting for payment confirmation.'}</CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-wrap gap-3">
                        {completed && provisioning.team ? <Button asChild><Link href={editTeam(provisioning.team.slug)}>{t('common.open')}</Link></Button> : null}
                        {!completed && !stopped ? <Button variant="outline" asChild><Link href={workspaceProvisioning.show(provisioning.id)}><RefreshCw className="mr-2 size-4" />{t('billing.refresh_status')}</Link></Button> : null}
                        {!completed && stopped ? <Form action={workspaceProvisioning.retry(provisioning.id)}><Button type="submit">{t('billing.try_payment_again')}</Button></Form> : null}
                        {notice ? <p className="w-full text-sm text-muted-foreground">{notice}</p> : null}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}
