import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { create, edit, store, update } from '@/routes/data-sources/graphql';

type Row = { name: string; value: string };
type Props = {
    dataSource?: { id: number; name: string; type: 'graphql_api'; config: Record<string, unknown> };
    templateContext?: { titleKey?: string; requirementKey?: string } | null;
    authTypes: { value: string; labelKey: string }[];
};

const emptyRow = (): Row => ({ name: '', value: '' });

function rowsFrom(value: unknown): Row[] {
    if (!value || typeof value !== 'object' || Array.isArray(value)) {
return [emptyRow()];
}

    const rows = Object.entries(value).map(([name, item]) => ({ name, value: String(item ?? '') }));

    return rows.length ? rows : [emptyRow()];
}

export default function GraphqlCreate({ dataSource, templateContext, authTypes }: Props) {
    const { t } = useTranslation();
    const { currentTeam } = usePage().props;
    const config = dataSource?.config ?? {};
    const form = useForm({
        protocol: 'graphql',
        name: dataSource?.name ?? '',
        endpoint: String(config.endpoint ?? ''),
        auth_type: String(config.auth_type ?? 'none'),
        api_key_placement: String(config.api_key_placement ?? 'header'),
        api_key_name: String(config.api_key_name ?? 'X-API-Key'),
        custom_header_name: String(config.custom_header_name ?? ''),
        bearer_token: '', api_key: '', basic_username: '', basic_password: '', custom_header_value: '',
        default_headers: rowsFrom(config.default_headers),
        default_query_parameters: rowsFrom(config.default_query_parameters),
        default_variables: rowsFrom(config.default_variables),
        template: '', requirement: '', capability: '', bot: '',
    });
    const [headers, setHeaders] = useState(rowsFrom(config.default_headers));
    const [queryParameters, setQueryParameters] = useState(rowsFrom(config.default_query_parameters));
    const [variables, setVariables] = useState(rowsFrom(config.default_variables));
    const isEditing = Boolean(dataSource);
    const slug = currentTeam?.slug;

    if (!slug) {
return null;
}

    const updateRows = (setter: typeof setHeaders, rows: Row[], index: number, key: keyof Row, value: string) => {
        setter(rows.map((row, rowIndex) => rowIndex === index ? { ...row, [key]: value } : row));
    };
    const addRow = (setter: typeof setHeaders, rows: Row[]) => setter([...rows, emptyRow()]);
    const removeRow = (setter: typeof setHeaders, rows: Row[], index: number) => setter(rows.length > 1 ? rows.filter((_, rowIndex) => rowIndex !== index) : [emptyRow()]);
    const objectFrom = (rows: Row[]) => Object.fromEntries(rows.filter((row) => row.name.trim()).map((row) => [row.name.trim(), row.value]));

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            default_headers: objectFrom(headers),
            default_query_parameters: objectFrom(queryParameters),
            default_variables: objectFrom(variables),
        }));

        if (dataSource) {
form.patch(update.url([slug, dataSource.id]));
} else {
form.post(store.url(slug));
}
    };

    return (
        <>
            <Head title={t('graphql_builder.connection_title')} />
            <div className="max-w-5xl space-y-6 p-4 md:p-6">
                <div className="flex items-start gap-3">
                    <Button variant="ghost" size="icon" asChild><Link href={dataSource ? edit.url([slug, dataSource.id]) : create.url(slug)} aria-label={t('common.back')}><ArrowLeft /></Link></Button>
                    <Heading variant="small" title={t('graphql_builder.connection_title')} description={t('graphql_builder.connection_description')} />
                </div>
                {templateContext?.titleKey ? <p className="rounded-lg border border-primary/30 bg-primary/5 p-3 text-sm">{t('api_builder.context_for')} {t(templateContext.titleKey)}</p> : null}
                <form onSubmit={submit} className="space-y-6">
                    <Card><CardHeader><CardTitle>{t('api_builder.connection_step')}</CardTitle></CardHeader><CardContent className="grid gap-5 md:grid-cols-2">
                        <div className="grid gap-2 md:col-span-2"><Label htmlFor="name">{t('common.name')}</Label><Input id="name" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} required /><InputError message={form.errors.name} /></div>
                        <div className="grid gap-2 md:col-span-2"><Label htmlFor="endpoint">{t('graphql_builder.endpoint')}</Label><Input id="endpoint" type="url" value={form.data.endpoint} onChange={(event) => form.setData('endpoint', event.target.value)} placeholder="https://api.example.com/graphql" required /><InputError message={form.errors.endpoint} /></div>
                        <div className="grid gap-2 md:col-span-2"><Label>{t('api_builder.authentication')}</Label><Select value={form.data.auth_type} onValueChange={(value) => form.setData('auth_type', value)}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent>{authTypes.map((item) => <SelectItem key={item.value} value={item.value}>{t(item.labelKey)}</SelectItem>)}</SelectContent></Select></div>
                        {form.data.auth_type === 'bearer' ? <div className="grid gap-2 md:col-span-2"><Label>{t('api_builder.token')}</Label><Input type="password" value={form.data.bearer_token} onChange={(event) => form.setData('bearer_token', event.target.value)} placeholder={isEditing ? t('api_builder.configured_placeholder') : ''} /></div> : null}
                        {form.data.auth_type === 'api_key' ? <><div className="grid gap-2"><Label>{t('api_builder.key_name')}</Label><Input value={form.data.api_key_name} onChange={(event) => form.setData('api_key_name', event.target.value)} /></div><div className="grid gap-2"><Label>{t('api_builder.key_placement')}</Label><Select value={form.data.api_key_placement} onValueChange={(value) => form.setData('api_key_placement', value)}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent><SelectItem value="header">{t('api_builder.header')}</SelectItem><SelectItem value="query">{t('api_builder.query_parameter')}</SelectItem></SelectContent></Select></div><div className="grid gap-2 md:col-span-2"><Label>{t('api_builder.key_value')}</Label><Input type="password" value={form.data.api_key} onChange={(event) => form.setData('api_key', event.target.value)} placeholder={isEditing ? t('api_builder.configured_placeholder') : ''} /></div></> : null}
                        {form.data.auth_type === 'basic' ? <><div className="grid gap-2"><Label>{t('api_builder.username')}</Label><Input value={form.data.basic_username} onChange={(event) => form.setData('basic_username', event.target.value)} /></div><div className="grid gap-2"><Label>{t('api_builder.password')}</Label><Input type="password" value={form.data.basic_password} onChange={(event) => form.setData('basic_password', event.target.value)} /></div></> : null}
                        {form.data.auth_type === 'custom_header' ? <><div className="grid gap-2"><Label>{t('api_builder.header_name')}</Label><Input value={form.data.custom_header_name} onChange={(event) => form.setData('custom_header_name', event.target.value)} /></div><div className="grid gap-2"><Label>{t('api_builder.header_value')}</Label><Input type="password" value={form.data.custom_header_value} onChange={(event) => form.setData('custom_header_value', event.target.value)} /></div></> : null}
                    </CardContent></Card>
                    {([['headers', headers, setHeaders, 'api_builder.default_headers'], ['query', queryParameters, setQueryParameters, 'api_builder.default_query'], ['variables', variables, setVariables, 'graphql_builder.default_variables']] as const).map(([kind, rows, setter, label]) => <Card key={kind}><CardHeader><CardTitle>{t(label)}</CardTitle></CardHeader><CardContent className="space-y-3">{rows.map((row, index) => <div className="flex gap-2" key={`${kind}-${index}`}><Input placeholder={t('api_builder.parameter_name')} value={row.name} onChange={(event) => updateRows(setter, rows, index, 'name', event.target.value)} /><Input placeholder={t('api_builder.parameter_value')} value={row.value} onChange={(event) => updateRows(setter, rows, index, 'value', event.target.value)} /><Button type="button" variant="ghost" size="icon" onClick={() => removeRow(setter, rows, index)} aria-label={t('common.delete')}><Trash2 /></Button></div>)}<Button type="button" variant="outline" onClick={() => addRow(setter, rows)}><Plus />{t('api_builder.add_parameter')}</Button></CardContent></Card>)}
                    <div className="flex gap-2"><Button type="submit" disabled={form.processing}>{form.processing ? t('common.saving') : t('graphql_builder.save_connection')}</Button><Button type="button" variant="outline" asChild><Link href={create.url(slug)}>{t('common.cancel')}</Link></Button></div>
                </form>
            </div>
        </>
    );
}
