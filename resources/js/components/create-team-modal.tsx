import { Form, Link, usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index as billingIndex } from '@/routes/billing';
import { store } from '@/routes/teams';
import workspaceProvisioning from '@/routes/workspace-provisioning';
import type { WorkspaceBilling } from '@/types';

export default function CreateTeamModal({ children, workspaceBilling: suppliedBilling }: PropsWithChildren<{ workspaceBilling?: WorkspaceBilling }>) {
    const { currentTeam, currentTeamPermissions, workspaceBilling: sharedBilling } = usePage().props;
    const { t } = useTranslation();
    const hasBilling = Boolean(suppliedBilling ?? sharedBilling);
    const workspaceBilling = suppliedBilling ?? sharedBilling ?? { free_available: true, plans: [] };
    const availableBilling = workspaceBilling;
    const [open, setOpen] = useState(false);
    const [planKey, setPlanKey] = useState(availableBilling.free_available ? 'free' : (availableBilling.plans.find((plan) => plan.key !== 'free' && plan.stripe_configured)?.key ?? 'free'));
    const canViewBilling = currentTeam !== null && currentTeamPermissions?.['billing.view'] === true;
    const billingUrl = currentTeam && canViewBilling ? billingIndex(currentTeam.slug).url : null;
    const selectedPlan = useMemo(() => workspaceBilling.plans.find((plan) => plan.key === planKey), [planKey, workspaceBilling.plans]);
    const isFree = planKey === 'free';
    const action = isFree ? store.form() : workspaceProvisioning.store.form();

    if (!hasBilling) {
        return null;
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{children}</DialogTrigger>
            <DialogContent>
                <Form key={String(open)} {...action} className="space-y-6" onSuccess={() => setOpen(false)}>
                    {({ errors, processing }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>{t('billing.create_workspace')}</DialogTitle>
                                <DialogDescription>Choose Free to use your one-time allowance, or continue to secure payment for a paid workspace.</DialogDescription>
                            </DialogHeader>
                            <div className="grid gap-2">
                                <Label htmlFor="name">{t('billing.workspace_name')}</Label>
                                <Input id="name" name="name" data-test="create-team-name" placeholder="My workspace" required />
                                <InputError message={errors.name} />
                            </div>
                            <fieldset className="grid gap-3">
                                <legend className="text-sm font-medium">{t('billing.select_plan')}</legend>
                                {workspaceBilling.plans.map((plan) => {
                                    const disabled = plan.key === 'free' ? !workspaceBilling.free_available : !plan.stripe_configured;

                                    return (
                                        <label key={plan.key} className={`flex items-start gap-3 rounded-lg border p-3 ${disabled ? 'cursor-not-allowed opacity-50' : 'cursor-pointer'}`}>
                                            <input type="radio" name="plan_key" value={plan.key} checked={planKey === plan.key} disabled={disabled} onChange={() => setPlanKey(plan.key)} className="mt-1" />
                                            <span className="min-w-0 flex-1">
                                                <span className="flex justify-between gap-3 font-medium"><span>{plan.name}</span><span className="text-sm text-muted-foreground">{plan.display_price ?? (plan.key === 'free' ? 'No charge' : 'Price at checkout')}</span></span>
                                                <span className="block text-sm text-muted-foreground">{plan.description}</span>
                                            </span>
                                        </label>
                                    );
                                })}
                                {!workspaceBilling.free_available ? <p className="text-sm text-muted-foreground">Your Free workspace allowance has been used. Choose a paid plan for another workspace.</p> : null}
                                <InputError message={errors.plan_key} />
                                <InputError message={errors.billing} />
                                {errors.billing && billingUrl ? <Link href={billingUrl} className="text-sm font-medium text-primary underline underline-offset-4">Open Billing</Link> : null}
                            </fieldset>
                            <DialogFooter className="gap-2">
                                <DialogClose asChild><Button variant="secondary">Cancel</Button></DialogClose>
                                <Button type="submit" data-test="create-team-submit" disabled={processing || !selectedPlan || (isFree && !workspaceBilling.free_available) || (!isFree && !selectedPlan.stripe_configured)}>{isFree ? t('billing.create_workspace') : t('billing.continue_to_payment')}</Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
