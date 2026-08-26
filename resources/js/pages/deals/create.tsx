import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { index as dealsIndex, store } from '@/routes/deals';
import type { DealDetailPageProps } from '@/types';

export default function DealCreate({
    pipelineOptions,
    customerOptions,
    leadOptions,
    currencyOptions,
    selectedPipelineId,
}: DealDetailPageProps) {
    const { currentTeam } = usePage().props;
    const [pipelineId, setPipelineId] = useState<number | string>(
        selectedPipelineId ?? pipelineOptions[0]?.id ?? '',
    );
    const pipeline = pipelineOptions.find(
        (item) => item.id === Number(pipelineId),
    );
    const [stageId, setStageId] = useState<number | string>(
        pipeline?.stages.find((stage) => stage.semanticType === 'open')?.id ??
            '',
    );

    if (!currentTeam) {
        return null;
    }

    const submit = (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const data = new FormData(event.currentTarget);
        data.set('pipeline_id', String(pipelineId));
        data.set('stage_id', String(stageId));
        router.post(store(currentTeam.slug).url, data);
    };

    return (
        <>
            <Head title="New deal" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <Link
                    href={dealsIndex(currentTeam.slug).url}
                    className="flex w-fit items-center gap-2 text-sm text-muted-foreground"
                >
                    <ArrowLeft className="size-4" />
                    Back to deals
                </Link>
                <Heading
                    variant="small"
                    title="New deal"
                    description="Create an open deal in your sales pipeline."
                />
                <form
                    onSubmit={submit}
                    className="grid max-w-3xl gap-4 rounded-xl border p-4 md:p-6"
                >
                    <label className="grid gap-2 text-sm">
                        Title
                        <input
                            name="title"
                            required
                            className="rounded-lg border bg-transparent px-3 py-2"
                        />
                    </label>
                    <label className="grid gap-2 text-sm">
                        Pipeline
                        <select
                            value={pipelineId}
                            onChange={(event) => {
                                setPipelineId(event.target.value);
                                const next = pipelineOptions.find(
                                    (item) =>
                                        item.id === Number(event.target.value),
                                );
                                setStageId(
                                    next?.stages.find(
                                        (stage) =>
                                            stage.semanticType === 'open',
                                    )?.id ?? '',
                                );
                            }}
                            className="rounded-lg border bg-transparent px-3 py-2"
                        >
                            {pipelineOptions.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="grid gap-2 text-sm">
                        Stage
                        <select
                            value={stageId}
                            onChange={(event) => setStageId(event.target.value)}
                            className="rounded-lg border bg-transparent px-3 py-2"
                        >
                            {pipeline?.stages
                                .filter(
                                    (stage) => stage.semanticType === 'open',
                                )
                                .map((stage) => (
                                    <option key={stage.id} value={stage.id}>
                                        {stage.name}
                                    </option>
                                ))}
                        </select>
                    </label>
                    <label className="grid gap-2 text-sm">
                        Customer
                        <select
                            name="customer_id"
                            defaultValue=""
                            className="rounded-lg border bg-transparent px-3 py-2"
                        >
                            <option value="">No customer</option>
                            {customerOptions.map((customer) => (
                                <option key={customer.id} value={customer.id}>
                                    {customer.name}{' '}
                                    {customer.email
                                        ? `· ${customer.email}`
                                        : ''}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="grid gap-2 text-sm">
                        Lead
                        <select
                            name="lead_id"
                            defaultValue=""
                            className="rounded-lg border bg-transparent px-3 py-2"
                        >
                            <option value="">No lead</option>
                            {leadOptions.map((lead) => (
                                <option key={lead.id} value={lead.id}>
                                    {lead.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <label className="grid gap-2 text-sm">
                            Value
                            <input
                                name="value_amount"
                                type="number"
                                min="0"
                                step="0.01"
                                className="rounded-lg border bg-transparent px-3 py-2"
                            />
                        </label>
                        <label className="grid gap-2 text-sm">
                            Currency
                            <select
                                name="currency"
                                defaultValue="USD"
                                className="rounded-lg border bg-transparent px-3 py-2"
                            >
                                {currencyOptions.map((currency) => (
                                    <option key={currency}>{currency}</option>
                                ))}
                            </select>
                        </label>
                    </div>
                    <label className="grid gap-2 text-sm">
                        Expected close date
                        <input
                            name="expected_close_date"
                            type="date"
                            className="rounded-lg border bg-transparent px-3 py-2"
                        />
                    </label>
                    <label className="grid gap-2 text-sm">
                        Description
                        <textarea
                            name="description"
                            rows={4}
                            className="rounded-lg border bg-transparent px-3 py-2"
                        />
                    </label>
                    <Button type="submit">Create deal</Button>
                </form>
            </div>
        </>
    );
}
