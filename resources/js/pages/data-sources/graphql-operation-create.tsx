import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, FlaskConical, Plus, Trash2 } from 'lucide-react';
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
import { create, store, test } from '@/routes/data-sources/api-operations';

type VariableRow = { name: string; source: 'fixed' | 'tool_argument' | 'context'; value: string; argument: string; context_key: string };
type FieldRow = { name: string; path: string; required: boolean };
type Props = { dataSource: { id: number; name: string; config: Record<string, unknown> }; templateContext?: { capability?: string; botId?: number | null } | null };

const variable = (): VariableRow => ({ name: '', source: 'tool_argument', value: '', argument: '', context_key: '' });
const field = (): FieldRow => ({ name: '', path: '', required: true });

export default function GraphqlOperationCreate({ dataSource, templateContext }: Props) {
    const { t } = useTranslation();
    const { currentTeam } = usePage().props;
    const form = useForm({
        protocol: 'graphql', key: '', name: '', usage: 'live_read', method: 'POST', path: '/',
        graphql_document: '', graphql_operation_name: '', graphql_variables: [variable()],
        records_path: '', response_fields: [{ name: 'result', path: '', required: true }] as FieldRow[],
        response_mapping: {}, pagination: { type: 'none', has_next_path: '', cursor_path: '', cursor_variable: 'after', page_size: 100, max_pages: 100 },
        timeout_ms: 10000, is_enabled: true, test_arguments: {}, capability: templateContext?.capability ?? '', bot: templateContext?.botId ?? '', input_mapping: [],
    });
    const [preview, setPreview] = useState<Record<string, unknown> | null>(null);
    const [testing, setTesting] = useState(false);
    const slug = currentTeam?.slug;

    if (!slug) {
return null;
}

    const updateVariable = (index: number, key: keyof VariableRow, value: string) => form.setData('graphql_variables', form.data.graphql_variables.map((row, rowIndex) => rowIndex === index ? { ...row, [key]: value } : row));
    const updateField = (index: number, key: keyof FieldRow, value: string | boolean) => form.setData('response_fields', form.data.response_fields.map((row, rowIndex) => rowIndex === index ? { ...row, [key]: value } : row));
    const runTest = async () => {
        setTesting(true);

        try {
            const response = await fetch(test.url([slug, dataSource.id]), { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '' }, body: JSON.stringify(form.data) });
            setPreview(await response.json());
        } finally {
 setTesting(false); 
}
    };
    const submit = (event: FormEvent) => {
 event.preventDefault(); form.post(store.url([slug, dataSource.id])); 
};

    return (
        <>
            <Head title={t('graphql_builder.operation_title')} />
            <div className="max-w-5xl space-y-6 p-4 md:p-6">
                <div className="flex items-start gap-3"><Button variant="ghost" size="icon" asChild><Link href={create.url([slug, dataSource.id])} aria-label={t('common.back')}><ArrowLeft /></Link></Button><Heading variant="small" title={t('graphql_builder.operation_title')} description={t('graphql_builder.operation_description')} /></div>
                <form onSubmit={submit} className="space-y-6">
                    <Card><CardHeader><CardTitle>{t('graphql_builder.document_section')}</CardTitle></CardHeader><CardContent className="grid gap-5 md:grid-cols-2">
                        <div className="grid gap-2"><Label>{t('api_builder.operation_name')}</Label><Input value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} required /><InputError message={form.errors.name} /></div>
                        <div className="grid gap-2"><Label>{t('api_builder.operation_key')}</Label><Input value={form.data.key} onChange={(event) => form.setData('key', event.target.value)} required /><InputError message={form.errors.key} /></div>
                        <div className="grid gap-2"><Label>{t('api_builder.usage')}</Label><Select value={form.data.usage} onValueChange={(value) => form.setData('usage', value)}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent><SelectItem value="synced">{t('api_builder.modes.synced')}</SelectItem><SelectItem value="live_read">{t('api_builder.modes.live_read')}</SelectItem><SelectItem value="live_write">{t('api_builder.modes.live_write')}</SelectItem></SelectContent></Select></div>
                        <div className="grid gap-2"><Label>{t('graphql_builder.operation_name_optional')}</Label><Input value={form.data.graphql_operation_name} onChange={(event) => form.setData('graphql_operation_name', event.target.value)} placeholder="Products" /></div>
                        <div className="grid gap-2 md:col-span-2"><Label>{t('graphql_builder.document')}</Label><textarea className="min-h-64 rounded-md border bg-background p-3 font-mono text-sm" value={form.data.graphql_document} onChange={(event) => form.setData('graphql_document', event.target.value)} placeholder={'query Products($first: Int!) { products(first: $first) { nodes { id name } } }'} required /><p className="text-sm text-muted-foreground">{t('graphql_builder.document_help')}</p><InputError message={form.errors.graphql_document} /></div>
                    </CardContent></Card>
                    <Card><CardHeader><CardTitle>{t('graphql_builder.variables')}</CardTitle></CardHeader><CardContent className="space-y-3">{form.data.graphql_variables.map((row, index) => <div className="grid gap-2 rounded-lg border p-3 md:grid-cols-5" key={`variable-${index}`}><Input placeholder="$name" value={row.name} onChange={(event) => updateVariable(index, 'name', event.target.value.replace(/^\$/, ''))} /><Select value={row.source} onValueChange={(value: VariableRow['source']) => updateVariable(index, 'source', value)}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent><SelectItem value="tool_argument">{t('api_builder.tool_argument')}</SelectItem><SelectItem value="fixed">{t('api_builder.fixed_value')}</SelectItem><SelectItem value="context">{t('api_builder.context_value')}</SelectItem></SelectContent></Select><Input placeholder={row.source === 'context' ? t('api_builder.context_key') : row.source === 'fixed' ? t('api_builder.configured_value') : t('api_builder.argument_name')} value={row.source === 'context' ? row.context_key : row.source === 'fixed' ? row.value : row.argument} onChange={(event) => updateVariable(index, row.source === 'context' ? 'context_key' : row.source === 'fixed' ? 'value' : 'argument', event.target.value)} /><div className="md:col-span-2 flex justify-end"><Button type="button" variant="ghost" size="icon" onClick={() => form.setData('graphql_variables', form.data.graphql_variables.filter((_, rowIndex) => rowIndex !== index))} aria-label={t('common.delete')}><Trash2 /></Button></div></div>)}<Button type="button" variant="outline" onClick={() => form.setData('graphql_variables', [...form.data.graphql_variables, variable()])}><Plus />{t('graphql_builder.add_variable')}</Button></CardContent></Card>
                    {form.data.usage === 'synced' ? <Card><CardHeader><CardTitle>{t('graphql_builder.sync_section')}</CardTitle></CardHeader><CardContent className="grid gap-4 md:grid-cols-2"><div className="grid gap-2 md:col-span-2"><Label>{t('api_builder.records_path')}</Label><Input value={form.data.records_path} onChange={(event) => form.setData('records_path', event.target.value)} placeholder="products.nodes" /><InputError message={form.errors.records_path} /></div><div className="grid gap-2"><Label>{t('graphql_builder.has_next_path')}</Label><Input value={form.data.pagination.has_next_path} onChange={(event) => form.setData('pagination', { ...form.data.pagination, type: 'relay_cursor', has_next_path: event.target.value })} placeholder="products.pageInfo.hasNextPage" /></div><div className="grid gap-2"><Label>{t('graphql_builder.cursor_path')}</Label><Input value={form.data.pagination.cursor_path} onChange={(event) => form.setData('pagination', { ...form.data.pagination, type: 'relay_cursor', cursor_path: event.target.value })} placeholder="products.pageInfo.endCursor" /></div><div className="grid gap-2"><Label>{t('graphql_builder.cursor_variable')}</Label><Input value={form.data.pagination.cursor_variable} onChange={(event) => form.setData('pagination', { ...form.data.pagination, type: 'relay_cursor', cursor_variable: event.target.value })} /></div><div className="grid gap-2"><Label>{t('graphql_builder.max_pages')}</Label><Input type="number" value={form.data.pagination.max_pages} onChange={(event) => form.setData('pagination', { ...form.data.pagination, max_pages: Number(event.target.value) })} /></div></CardContent></Card> : <Card><CardHeader><CardTitle>{t('graphql_builder.response_section')}</CardTitle></CardHeader><CardContent className="space-y-3">{form.data.response_fields.map((row, index) => <div className="flex gap-2" key={`field-${index}`}><Input placeholder={t('api_builder.output_name')} value={row.name} onChange={(event) => updateField(index, 'name', event.target.value)} /><Input placeholder={t('api_builder.response_path')} value={row.path} onChange={(event) => updateField(index, 'path', event.target.value)} /><Button type="button" variant="ghost" size="icon" onClick={() => form.setData('response_fields', form.data.response_fields.filter((_, rowIndex) => rowIndex !== index))}><Trash2 /></Button></div>)}<Button type="button" variant="outline" onClick={() => form.setData('response_fields', [...form.data.response_fields, field()])}><Plus />{t('graphql_builder.add_field')}</Button></CardContent></Card>}
                    <div className="flex flex-wrap gap-2"><Button type="button" variant="outline" onClick={runTest} disabled={testing}><FlaskConical />{form.data.usage === 'live_write' ? t('graphql_builder.preview_mutation') : t('graphql_builder.test_query')}</Button><Button type="submit" disabled={form.processing}>{form.processing ? t('common.saving') : t('graphql_builder.save_operation')}</Button></div>
                </form>
                {preview ? <Card><CardHeader><CardTitle>{t('api_builder.safe_preview')}</CardTitle></CardHeader><CardContent><pre className="max-h-96 overflow-auto rounded-lg bg-muted p-4 text-sm whitespace-pre-wrap">{JSON.stringify(preview, null, 2)}</pre></CardContent></Card> : null}
            </div>
        </>
    );
}
