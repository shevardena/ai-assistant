import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { show as customerShow, merge as customerMerge } from '@/routes/customers';
import type { CustomerMergePreview } from '@/types';

export default function CustomerMerge({ preview }: { preview: CustomerMergePreview }) {
    const { currentTeam } = usePage().props;

    if (!currentTeam) {
return null;
}

    const execute = () => router.post(customerMerge([currentTeam.slug, preview.source.id]).url, { destination_id: preview.destination.id });
    const conflicts = [...preview.conflicts.identities, ...preview.conflicts.customFields, ...preview.conflicts.facts];

    return <><Head title="Merge customers" /><div className="flex flex-col gap-6 p-4 md:p-6"><Link href={customerShow([currentTeam.slug, preview.source.id]).url} className="flex items-center gap-2 text-sm text-muted-foreground"><ArrowLeft className="size-4" /> Back to source profile</Link><Card><CardHeader><CardTitle>Review customer merge</CardTitle></CardHeader><CardContent className="grid gap-4"><p className="text-sm text-muted-foreground">The destination profile wins scalar conflicts. Linked records, tags, notes, facts, and non-conflicting identities move transactionally.</p><div className="grid gap-4 md:grid-cols-2"><div className="rounded-lg border p-4"><p className="text-sm text-muted-foreground">Source</p><p className="font-medium">{preview.source.name}</p><p className="text-sm">{preview.source.email || preview.source.phone || 'No contact identity'}</p></div><div className="rounded-lg border p-4"><p className="text-sm text-muted-foreground">Destination</p><p className="font-medium">{preview.destination.name}</p><p className="text-sm">{preview.destination.email || preview.destination.phone || 'No contact identity'}</p></div></div>{conflicts.length ? <div className="rounded-lg border border-destructive/50 p-4"><p className="font-medium">Resolve identity conflicts before merging.</p><ul className="mt-2 list-disc pl-5 text-sm">{conflicts.map((conflict, index) => <li key={index}>{'type' in conflict ? `${conflict.type}: ${conflict.value}` : 'key' in conflict ? `${conflict.key}: destination value wins` : `${conflict.field}: destination value wins`}</li>)}</ul></div> : <p className="text-sm text-muted-foreground">No blocking identity conflicts found.</p>}<Button disabled={preview.blocked} onClick={execute}>Confirm merge</Button></CardContent></Card></div></>;
}
