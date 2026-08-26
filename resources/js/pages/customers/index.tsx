import { Head, Link, router, usePage } from '@inertiajs/react';
import { Search, UsersRound } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { index as customerFieldsIndex } from '@/routes/customer-fields';
import { index as customerSegmentsIndex } from '@/routes/customer-segments';
import { index as customersIndex, show as customersShow, store as customersStore } from '@/routes/customers';
import type { CustomerFilters, CustomerPageProps, CustomerStatus, Paginated } from '@/types';

function date(value: string | null): string {
 return value ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '—'; 
}
function pagination(page: Paginated<unknown>) {
    if (page.last_page <= 1) {
return null;
}

    return <nav className="flex flex-wrap justify-center gap-2" aria-label="Customers pagination">{page.links.map((link, index) => link.url ? <Button key={`${link.label}-${index}`} size="sm" variant={link.active ? 'default' : 'outline'} asChild><Link href={link.url}>{link.label.replace('&laquo;', 'Previous').replace('&raquo;', 'Next')}</Link></Button> : <Button key={`${link.label}-${index}`} size="sm" variant="outline" disabled>{link.label.replace('&laquo;', 'Previous').replace('&raquo;', 'Next')}</Button>)}</nav>;
}

export default function CustomersIndex({ filters, customers, statusOptions, ownerOptions, tagOptions, segmentOptions }: CustomerPageProps) {
    const { t } = useTranslation();
    const { currentTeam } = usePage().props;
    const [search, setSearch] = useState(filters.search ?? '');

    if (!currentTeam) {
return null;
}

    const visit = (changes: Partial<CustomerFilters>) => router.get(customersIndex(currentTeam.slug, { query: { ...filters, ...changes } }).url);
    const submit = (event: FormEvent<HTMLFormElement>) => {
 event.preventDefault(); visit({ search: search.trim() || null }); 
};
    const create = (event: FormEvent<HTMLFormElement>) => {
 event.preventDefault(); router.post(customersStore(currentTeam.slug).url, Object.fromEntries(new FormData(event.currentTarget))); 
};

    return <><Head title={t('navigation.customers')} /><div className="flex flex-col gap-6 p-4 md:p-6">
        <div className="flex flex-wrap items-end justify-between gap-3"><Heading variant="small" title={t('navigation.customers')} description="One profile for every trusted customer relationship." /><div className="flex gap-2"><Link href={customerFieldsIndex(currentTeam.slug).url}><Button variant="outline" size="sm">Fields</Button></Link><Link href={customerSegmentsIndex(currentTeam.slug).url}><Button variant="outline" size="sm">Segments</Button></Link></div></div>
        <Card><CardContent className="p-4"><form onSubmit={create} className="grid gap-3 md:grid-cols-2 lg:grid-cols-4">
            <input name="first_name" placeholder="First name" className="rounded-lg border bg-transparent px-3 py-2 text-sm" />
            <input name="last_name" placeholder="Last name" className="rounded-lg border bg-transparent px-3 py-2 text-sm" />
            <input name="email" type="email" placeholder="Email" className="rounded-lg border bg-transparent px-3 py-2 text-sm" />
            <input name="phone" placeholder="Phone" className="rounded-lg border bg-transparent px-3 py-2 text-sm" />
            <input name="company" placeholder="Company" className="rounded-lg border bg-transparent px-3 py-2 text-sm" />
            <select name="status" defaultValue="new" className="rounded-lg border bg-transparent px-3 py-2 text-sm"><option value="new">New</option>{statusOptions.map((option) => <option key={option.key} value={option.key}>{option.label}</option>)}</select>
            <select name="owner_id" defaultValue="" className="rounded-lg border bg-transparent px-3 py-2 text-sm"><option value="">Unassigned</option>{ownerOptions.map((owner) => <option key={owner.id} value={owner.id}>{owner.name}</option>)}</select>
            <Button type="submit">Add customer</Button>
        </form></CardContent></Card>
        <div className="flex flex-col gap-3 rounded-xl border p-3 lg:flex-row"><form onSubmit={submit} className="flex min-w-0 flex-1 gap-2"><div className="flex min-w-0 flex-1 items-center gap-2 rounded-lg border px-3"><Search className="size-4 text-muted-foreground" /><input aria-label="Search customers" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search name, email, phone, or company" className="min-w-0 flex-1 bg-transparent py-2 text-sm outline-none" /></div><Button type="submit" variant="outline">Search</Button></form><select aria-label="Filter by status" value={filters.status} onChange={(event) => visit({ status: event.target.value as CustomerStatus | 'all' })} className="rounded-lg border bg-transparent px-3 py-2 text-sm"><option value="all">All statuses</option>{statusOptions.map((option) => <option key={option.key} value={option.key}>{option.label}</option>)}</select><select aria-label="Filter by owner" value={filters.ownerId ?? ''} onChange={(event) => visit({ ownerId: event.target.value ? Number(event.target.value) : null })} className="rounded-lg border bg-transparent px-3 py-2 text-sm"><option value="">All owners</option>{ownerOptions.map((owner) => <option key={owner.id} value={owner.id}>{owner.name}</option>)}</select><select aria-label="Filter by tag" value={filters.tag ?? ''} onChange={(event) => visit({ tag: event.target.value ? Number(event.target.value) : null })} className="rounded-lg border bg-transparent px-3 py-2 text-sm"><option value="">All tags</option>{tagOptions.map((tag) => <option key={tag.id} value={tag.id}>{tag.name}</option>)}</select><select aria-label="Filter by segment" value={filters.segment ?? ''} onChange={(event) => visit({ segment: event.target.value ? Number(event.target.value) : null })} className="rounded-lg border bg-transparent px-3 py-2 text-sm"><option value="">All segments</option>{segmentOptions.map((segment) => <option key={segment.id} value={segment.id}>{segment.name}</option>)}</select></div>
        {customers.data.length === 0 ? <div className="rounded-xl border border-dashed p-10 text-center"><UsersRound className="mx-auto size-8 text-muted-foreground" /><p className="mt-3 font-medium">No customers found</p></div> : <div className="overflow-hidden rounded-xl border"><div className="hidden grid-cols-[minmax(14rem,1.5fr)_minmax(10rem,1fr)_minmax(8rem,1fr)_10rem_10rem] gap-4 border-b bg-muted/30 px-4 py-3 text-sm text-muted-foreground md:grid"><span>Customer</span><span>Company</span><span>Status</span><span>Owner</span><span>Last activity</span></div><div className="divide-y">{customers.data.map((customer) => <Link key={customer.id} href={customersShow([currentTeam.slug, customer.id]).url} className="grid gap-3 px-4 py-4 transition-colors hover:bg-muted/30 md:grid-cols-[minmax(14rem,1.5fr)_minmax(10rem,1fr)_minmax(8rem,1fr)_10rem_10rem] md:items-center md:gap-4"><div className="min-w-0"><p className="truncate font-medium">{customer.name}</p><p className="truncate text-sm text-muted-foreground">{customer.email ?? customer.phone ?? 'No contact details'}</p></div><span className="text-sm">{customer.company ?? '—'}</span><Badge variant={customer.status === 'inactive' ? 'outline' : 'secondary'}>{customer.statusLabel}</Badge><span className="text-sm">{customer.owner?.name ?? 'Unassigned'}</span><span className="text-sm text-muted-foreground">{date(customer.lastActivityAt)}</span></Link>)}</div></div>}
        {pagination(customers)}
    </div></>;
}
