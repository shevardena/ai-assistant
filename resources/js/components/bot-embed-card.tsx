import { Check, Copy } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { BotWidget } from '@/types';

export default function BotEmbedCard({ widget }: { widget: BotWidget }) {
    const [copied, setCopied] = useState(false);

    async function copySnippet() {
        await navigator.clipboard.writeText(widget.snippet);
        setCopied(true);
        window.setTimeout(() => setCopied(false), 1800);
    }

    return (
        <Card>
            <CardHeader>
                <div className="flex items-center justify-between gap-3">
                    <CardTitle>Website widget</CardTitle>
                    <Badge variant={widget.ready ? 'default' : 'secondary'}>
                        {widget.ready ? 'Ready' : 'Setup needed'}
                    </Badge>
                </div>
                <p className="text-sm text-muted-foreground">
                    Copy this code into an allowed website to add the chat
                    bubble.
                </p>
                <div className="flex flex-wrap gap-3 text-sm">
                    <span>Datasets: {widget.datasetCount}</span>
                    <span>Allowed domains: {widget.domainCount}</span>
                </div>
            </CardHeader>
            <CardContent className="grid gap-3">
                {!widget.ready ? (
                    <p className="text-sm text-muted-foreground">
                        The Bot needs a ready Dataset and at least one allowed
                        domain before it can answer publicly.
                    </p>
                ) : null}
                <pre className="overflow-x-auto rounded-lg bg-muted p-3 text-xs whitespace-pre-wrap">
                    <code>{widget.snippet}</code>
                </pre>
                <Button type="button" variant="outline" onClick={copySnippet}>
                    {copied ? <Check /> : <Copy />}
                    {copied ? 'Copied' : 'Copy code'}
                </Button>
            </CardContent>
        </Card>
    );
}
