import { Form, router } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { destroy, store } from '@/routes/bots/domains';
import type { BotDomain } from '@/types';

type Props = {
    domains: BotDomain[];
    currentTeamSlug: string;
    botId: number;
};

export default function BotDomainManager({
    domains,
    currentTeamSlug,
    botId,
}: Props) {
    return (
        <div className="grid gap-5">
            <Form
                {...store.form([currentTeamSlug, botId])}
                options={{ preserveScroll: true }}
                className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto]"
            >
                {({ errors, processing }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="domain">Add domain</Label>
                            <Input
                                id="domain"
                                name="domain"
                                placeholder="example.com"
                                autoComplete="off"
                            />
                            <InputError message={errors.domain} />
                        </div>
                        <Button
                            type="submit"
                            disabled={processing}
                            className="self-end"
                        >
                            {processing ? 'Adding...' : 'Add domain'}
                        </Button>
                    </>
                )}
            </Form>

            {domains.length > 0 ? (
                <ul className="grid gap-2">
                    {domains.map((domain) => (
                        <DomainRow
                            key={domain.id}
                            domain={domain}
                            currentTeamSlug={currentTeamSlug}
                            botId={botId}
                        />
                    ))}
                </ul>
            ) : (
                <p className="text-sm text-muted-foreground">
                    Add the website host where this widget will be embedded.
                </p>
            )}
        </div>
    );
}

function DomainRow({
    domain,
    currentTeamSlug,
    botId,
}: {
    domain: BotDomain;
    currentTeamSlug: string;
    botId: number;
}) {
    const [processing, setProcessing] = useState(false);

    function remove() {
        setProcessing(true);
        router.delete(destroy.url([currentTeamSlug, botId, domain.id]), {
            preserveScroll: true,
            onFinish: () => setProcessing(false),
        });
    }

    return (
        <li className="flex items-center justify-between gap-3 rounded-lg border px-3 py-2">
            <code className="text-sm">{domain.domain}</code>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                aria-label={'Remove ' + domain.domain}
                disabled={processing}
                onClick={remove}
            >
                <Trash2 />
            </Button>
        </li>
    );
}
