import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { index as fieldsIndex } from '@/routes/customer-fields';
import { destroy as segmentDestroy, store as segmentStore } from '@/routes/customer-segments';
import { index as customersIndex } from '@/routes/customers';
import type { CustomerSegmentPageProps } from '@/types';

export default function CustomerSegments({ segments, filterOptions }: CustomerSegmentPageProps) {
    const { currentTeam } = usePage().props;
    const [form, setForm] = useState({ name: '', description: '', status: '' });

    if (!currentTeam) {
return null;
}

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        router.post(segmentStore(currentTeam.slug).url, { name: form.name, description: form.description, filter_definition: { filters: form.status ? [{ field: 'status', operator: 'equals', value: form.status }] : [] } }, { onSuccess: () => setForm({ name: '', description: '', status: '' }) });
    };

    return <><Head title="Customer segments" /><div className="flex flex-col gap-6 p-4 md:p-6"><div className="flex flex-wrap items-center justify-between gap-3"><Link href={customersIndex(currentTeam.slug).url} className="flex items-center gap-2 text-sm text-muted-foreground"><ArrowLeft className="size-4" /> Back to Customers</Link><Link href={fieldsIndex(currentTeam.slug).url}><Button variant="outline" size="sm">Fields</Button></Link></div><Heading variant="small" title="Customer segments" description="Deterministic, team-scoped groups for customer list filtering." /><div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]"><Card><CardHeader className="border-b"><CardTitle className="text-base">Saved segments</CardTitle></CardHeader><CardContent className="divide-y p-0">{segments.length === 0 ? <p className="p-4 text-sm text-muted-foreground">No segments yet.</p> : segments.map((segment) => <div key={segment.id} className="flex items-center justify-between gap-3 p-4"><div><Link href={customersIndex(currentTeam.slug, { query: { segment: segment.id } }).url} className="font-medium hover:underline">{segment.name}</Link><p className="text-sm text-muted-foreground">{segment.description || 'No description'} · {segment.matchingCount} matching</p></div><Button size="icon" variant="ghost" onClick={() => router.delete(segmentDestroy([currentTeam.slug, segment.id]).url, { preserveScroll: true })}><Trash2 className="size-4" /></Button></div>)}</CardContent></Card><Card><CardHeader className="border-b"><CardTitle className="text-base">Add segment</CardTitle></CardHeader><CardContent className="p-4"><form onSubmit={submit} className="grid gap-3"><input required value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} placeholder="Segment name" className="rounded-lg border bg-transparent px-3 py-2 text-sm" /><textarea value={form.description} onChange={(event) => setForm({ ...form, description: event.target.value })} placeholder="Description" className="min-h-20 rounded-lg border bg-transparent px-3 py-2 text-sm" /><select value={form.status} onChange={(event) => setForm({ ...form, status: event.target.value })} className="rounded-lg border bg-transparent px-3 py-2 text-sm"><option value="">All customers</option>{filterOptions.statuses.map((status) => <option key={status.key} value={status.key}>{status.label}</option>)}</select><Button type="submit">Save segment</Button></form></CardContent></Card></div></div></>;
}
