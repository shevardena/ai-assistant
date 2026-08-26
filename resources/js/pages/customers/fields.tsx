import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { status as fieldStatus, store as fieldStore } from '@/routes/customer-fields';
import { index as segmentsIndex } from '@/routes/customer-segments';
import { index as customersIndex } from '@/routes/customers';
import type { CustomerCustomFieldType, CustomerFieldPageProps } from '@/types';

export default function CustomerFields({ fields, types }: CustomerFieldPageProps) {
    const { currentTeam } = usePage().props;
    const [form, setForm] = useState({ key: '', label: '', type: 'text' as CustomerCustomFieldType, options: '' });

    if (!currentTeam) {
return null;
}

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        router.post(fieldStore(currentTeam.slug).url, { ...form, options: form.options.split(',').map((option) => option.trim()).filter(Boolean) }, { onSuccess: () => setForm({ key: '', label: '', type: 'text', options: '' }) });
    };

    return <><Head title="Customer fields" /><div className="flex flex-col gap-6 p-4 md:p-6"><div className="flex flex-wrap items-center justify-between gap-3"><Link href={customersIndex(currentTeam.slug).url} className="flex items-center gap-2 text-sm text-muted-foreground"><ArrowLeft className="size-4" /> Back to Customers</Link><Link href={segmentsIndex(currentTeam.slug).url}><Button variant="outline" size="sm">Segments</Button></Link></div><Heading variant="small" title="Custom fields" description="Team-scoped fields shown on every Customer 360 profile." /><div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]"><Card><CardHeader className="border-b"><CardTitle className="text-base">Configured fields</CardTitle></CardHeader><CardContent className="divide-y p-0">{fields.length === 0 ? <p className="p-4 text-sm text-muted-foreground">No custom fields yet.</p> : fields.map((field) => <div key={field.id} className="flex flex-wrap items-center justify-between gap-3 p-4"><div><p className="font-medium">{field.label}</p><p className="text-sm text-muted-foreground">{field.key} · {field.type}{field.required ? ' · required' : ''}</p>{field.options.length ? <p className="text-xs text-muted-foreground">{field.options.join(', ')}</p> : null}</div><Button size="sm" variant="outline" onClick={() => router.patch(fieldStatus([currentTeam.slug, field.id]).url, {}, { preserveScroll: true })}>{field.active ? 'Disable' : 'Enable'}</Button></div>)}</CardContent></Card><Card><CardHeader className="border-b"><CardTitle className="text-base">Add field</CardTitle></CardHeader><CardContent className="p-4"><form onSubmit={submit} className="grid gap-3"><input required value={form.key} onChange={(event) => setForm({ ...form, key: event.target.value })} placeholder="key_name" className="rounded-lg border bg-transparent px-3 py-2 text-sm" /><input required value={form.label} onChange={(event) => setForm({ ...form, label: event.target.value })} placeholder="Field label" className="rounded-lg border bg-transparent px-3 py-2 text-sm" /><select value={form.type} onChange={(event) => setForm({ ...form, type: event.target.value as CustomerCustomFieldType })} className="rounded-lg border bg-transparent px-3 py-2 text-sm">{types.map((type) => <option key={type.key} value={type.key}>{type.label}</option>)}</select><input value={form.options} onChange={(event) => setForm({ ...form, options: event.target.value })} placeholder="Options, comma separated" className="rounded-lg border bg-transparent px-3 py-2 text-sm" /><Button type="submit">Create field</Button></form></CardContent></Card></div></div></>;
}
