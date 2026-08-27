import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, FlaskConical, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { create, store, test, update } from '@/routes/data-sources/api-operations';
import { show as showDataSource } from '@/routes/data-sources';

type Props = {
    dataSource: { id: number; name: string; config: Record<string, unknown> };
    bots: { id: number; name: string }[];
    templateContext?: {
        requirementKey: string;
        capability?: string;
        botId?: number | null;
    } | null;
    operation?: {
        id: number;
        bot_id?: number | null;
        key: string;
        name: string;
        usage: string;
        method: string;
        path: string;
        records_path: string;
        capability: string;
        headers: KeyValueRow[];
        query_parameters: ParameterRow[];
        body_parameters: ParameterRow[];
        response_fields: ResponseField[];
        response_mapping?: Record<string, unknown>;
        live_query?: LiveQueryMapping;
        pagination: Record<string, unknown>;
        timeout_ms: number;
        is_enabled: boolean;
        input_mapping: InputMappingRow[];
    };
};

type ParameterRow = {
    name: string;
    source: 'fixed' | 'tool_argument';
    value: string;
    argument: string;
    required: boolean;
    type: 'string' | 'integer' | 'number' | 'boolean';
};

type ResponseField = {
    name: string;
    path: string;
    required: boolean;
    type: FieldType;
    searchable: boolean;
    filterable: boolean;
    sortable: boolean;
    displayable: boolean;
};

type FieldType = 'string' | 'integer' | 'decimal' | 'boolean' | 'date' | 'datetime';

type LiveFilterMapping = { field: string; operator: string; remote: string };
type LiveQueryMapping = { search_text: string; filters: LiveFilterMapping[] };

type InputMappingRow = {
    model_input: string;
    source: 'model_input' | 'dataset_field' | 'context_value';
    dataset_field: string;
    context_key: string;
    operation_argument: string;
};

type KeyValueRow = { name: string; value: string };

const emptyParameter = (): ParameterRow => ({
    name: '',
    source: 'tool_argument',
    value: '',
    argument: '',
    required: false,
    type: 'string',
});

const emptyResponseField = (): ResponseField => ({
    name: '',
    path: '',
    required: false,
    type: 'string',
    searchable: true,
    filterable: true,
    sortable: true,
    displayable: true,
});

const emptyInputMapping = (): InputMappingRow => ({
    model_input: '',
    source: 'model_input',
    dataset_field: '',
    context_key: '',
    operation_argument: '',
});

const emptyKeyValue = (): KeyValueRow => ({ name: '', value: '' });

export default function ApiOperationCreate({
    dataSource,
    bots,
    templateContext,
    operation,
}: Props) {
    const { t } = useTranslation();
    const { currentTeam } = usePage().props;
    const form = useForm({
        key: operation?.key ?? '',
        name: operation?.name ?? '',
        usage: operation?.usage ?? 'live_read',
        method: operation?.method ?? 'GET',
        path: operation?.path ?? '/',
        records_path: operation?.records_path ?? 'root',
        capability: operation?.capability ?? templateContext?.capability ?? '',
        headers: operation?.headers ?? ([] as KeyValueRow[]),
        query_parameters: operation?.query_parameters ?? ([] as ParameterRow[]),
        body_parameters: operation?.body_parameters ?? ([] as ParameterRow[]),
        response_fields: operation?.response_fields ?? [
            { ...emptyResponseField(), name: 'status', path: 'status', required: true },
        ] as ResponseField[],
        response_mapping: {},
        live_query: operation?.live_query ?? { search_text: '', filters: [] },
        pagination: operation?.pagination ?? { type: 'none' },
        timeout_ms: operation?.timeout_ms ?? 10000,
        is_enabled: operation?.is_enabled ?? true,
        test_arguments: {},
        bot: operation?.bot_id ?? templateContext?.botId ?? '',
        input_mapping: operation?.input_mapping ?? ([] as InputMappingRow[]),
    });
    const existingCollection = operation?.response_mapping?.collection;
    const [responseMode, setResponseMode] = useState<'object' | 'collection'>(
        existingCollection && typeof existingCollection === 'object'
            ? 'collection'
            : 'object',
    );
    const [collectionPath, setCollectionPath] = useState(
        existingCollection &&
            typeof existingCollection === 'object' &&
            'path' in existingCollection &&
            typeof existingCollection.path === 'string'
            ? existingCollection.path
            : '',
    );
    const [collectionFields, setCollectionFields] = useState<ResponseField[]>(
        existingCollection &&
            typeof existingCollection === 'object' &&
            'fields' in existingCollection &&
            existingCollection.fields &&
            typeof existingCollection.fields === 'object'
            ? Object.entries(existingCollection.fields).map(([name, definition]) => ({
                  name,
                  path:
                      typeof definition === 'string'
                          ? definition
                          : definition &&
                              typeof definition === 'object' &&
                              'path' in definition &&
                              typeof definition.path === 'string'
                            ? definition.path
                            : '',
                  required:
                      definition &&
                      typeof definition === 'object' &&
                      'required' in definition
                        ? Boolean(definition.required)
                        : true,
                  type: (definition && typeof definition === 'object' && ['string', 'integer', 'decimal', 'boolean', 'date', 'datetime'].includes(String(definition.type)) ? definition.type : 'string') as FieldType,
                  searchable: definition && typeof definition === 'object' && 'searchable' in definition ? Boolean(definition.searchable) : true,
                  filterable: definition && typeof definition === 'object' && 'filterable' in definition ? Boolean(definition.filterable) : true,
                  sortable: definition && typeof definition === 'object' && 'sortable' in definition ? Boolean(definition.sortable) : true,
                  displayable: definition && typeof definition === 'object' && 'displayable' in definition ? Boolean(definition.displayable) : true,
              }))
            : [emptyResponseField()],
    );
    const [preview, setPreview] = useState<Record<string, unknown> | null>(
        null,
    );
    const [testing, setTesting] = useState(false);
    const currentTeamSlug = currentTeam?.slug;

    const pathArguments = Array.from(
        form.data.path.matchAll(/\{([A-Za-z_][A-Za-z0-9_]*)\}/g),
    ).map((match) => match[1]);

    const updateParameter = (
        section: 'query_parameters' | 'body_parameters',
        index: number,
        key: keyof ParameterRow,
        value: string | boolean,
    ) => {
        const rows = form.data[section].map((row, rowIndex) =>
            rowIndex === index ? { ...row, [key]: value } : row,
        );
        form.setData(section, rows);
    };

    const addParameter = (section: 'query_parameters' | 'body_parameters') =>
        form.setData(section, [...form.data[section], emptyParameter()]);

    const removeParameter = (
        section: 'query_parameters' | 'body_parameters',
        index: number,
    ) =>
        form.setData(
            section,
            form.data[section].filter((_, i) => i !== index),
        );

    const updateResponseField = (
        index: number,
        key: keyof ResponseField,
        value: string | boolean,
    ) =>
        form.setData(
            'response_fields',
            form.data.response_fields.map((field, fieldIndex) =>
                fieldIndex === index ? { ...field, [key]: value } : field,
            ),
        );

    const updateInputMapping = (
        index: number,
        key: keyof InputMappingRow,
        value: string,
    ) =>
        form.setData(
            'input_mapping',
            form.data.input_mapping.map((row, rowIndex) =>
                rowIndex === index ? { ...row, [key]: value } : row,
            ),
        );

    const updateHeader = (
        index: number,
        key: keyof KeyValueRow,
        value: string,
    ) =>
        form.setData(
            'headers',
            form.data.headers.map((row, rowIndex) =>
                rowIndex === index ? { ...row, [key]: value } : row,
            ),
        );

    if (!currentTeamSlug) {
        return null;
    }

    const runTest = async () => {
        setTesting(true);

        try {
            const response = await fetch(
                test.url([currentTeamSlug, dataSource.id]),
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN':
                            document
                                .querySelector('meta[name="csrf-token"]')
                                ?.getAttribute('content') ?? '',
                        Accept: 'application/json',
                    },
                    body: JSON.stringify(form.data),
                },
            );
            const payload = await response.json();
            setPreview(payload);
        } finally {
            setTesting(false);
        }
    };

    return (
        <>
            <Head title={t('api_builder.operation_title')} />
            <div className="max-w-4xl space-y-6 p-4 md:p-6">
                <div className="flex items-start gap-3">
                    <Button variant="ghost" size="icon" asChild>
                        <Link
                            href={
                                operation
                                    ? showDataSource([
                                          currentTeamSlug,
                                          dataSource.id,
                                      ]).url
                                    : create.url([
                                          currentTeamSlug,
                                          dataSource.id,
                                      ])
                            }
                            aria-label={t('common.back')}
                        >
                            <ArrowLeft />
                        </Link>
                    </Button>
                    <Heading
                        variant="small"
                        title={t('api_builder.operation_title')}
                        description={t('api_builder.operation_description')}
                    />
                </div>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                                form.transform((data) => ({
                            ...data,
                            response_mapping:
                                responseMode === 'collection'
                                    ? {
                                          pagination: data.pagination,
                                          collection: {
                                              path: collectionPath,
                                              fields: Object.fromEntries(
                                                  collectionFields
                                                      .filter(
                                                          (field) =>
                                                              field.name &&
                                                              field.path,
                                                      )
                                                      .map((field) => [
                                                          field.name,
                                                          {
                                                              path: field.path,
                                                              required: field.required,
                                                              type: field.type,
                                                              searchable: field.searchable,
                                                              filterable: field.filterable,
                                                              sortable: field.sortable,
                                                              displayable: field.displayable,
                                                          },
                                                      ]),
                                              ),
                                          },
                                      }
                                : { pagination: data.pagination },
                        }));
                        operation
                            ? form.put(update.url([
                                  currentTeamSlug,
                                  dataSource.id,
                                  operation.id,
                              ]))
                            : form.post(store.url([currentTeamSlug, dataSource.id]));
                    }}
                    className="space-y-6"
                >
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {t('api_builder.endpoint_step')}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-5 md:grid-cols-2">
                            <div className="grid gap-2">
                                <Label>{t('api_builder.operation_name')}</Label>
                                <Input
                                    value={form.data.name}
                                    onChange={(e) =>
                                        form.setData('name', e.target.value)
                                    }
                                    required
                                />
                                <InputError message={form.errors.name} />
                            </div>
                            <div className="grid gap-2">
                                <Label>{t('api_builder.operation_key')}</Label>
                                <Input
                                    value={form.data.key}
                                    onChange={(e) =>
                                        form.setData('key', e.target.value)
                                    }
                                    placeholder="check-order"
                                    required
                                />
                                <InputError message={form.errors.key} />
                            </div>
                            <div className="grid gap-2">
                                <Label>{t('api_builder.usage')}</Label>
                                <Select
                                    value={form.data.usage}
                                    onValueChange={(value) => {
                                        form.setData('usage', value);
                                        form.setData(
                                            'method',
                                            value === 'synced'
                                                ? 'GET'
                                                : value === 'live_write'
                                                  ? 'POST'
                                                  : 'GET',
                                        );
                                    }}
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="synced">
                                            {t('api_builder.modes.synced')}
                                        </SelectItem>
                                        <SelectItem value="live_read">
                                            {t('api_builder.modes.live_read')}
                                        </SelectItem>
                                        <SelectItem value="live_write">
                                            {t('api_builder.modes.live_write')}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="grid gap-2">
                                <Label>{t('api_builder.method')}</Label>
                                <Select
                                    value={form.data.method}
                                    onValueChange={(value) =>
                                        form.setData('method', value)
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {(form.data.usage === 'synced'
                                            ? ['GET']
                                            : form.data.usage === 'live_write'
                                              ? [
                                                    'POST',
                                                    'PUT',
                                                    'PATCH',
                                                    'DELETE',
                                                ]
                                              : ['GET', 'POST']
                                        ).map((method) => (
                                            <SelectItem
                                                key={method}
                                                value={method}
                                            >
                                                {method}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="grid gap-2 md:col-span-2">
                                <Label>{t('api_builder.path')}</Label>
                                <Input
                                    value={form.data.path}
                                    onChange={(e) =>
                                        form.setData('path', e.target.value)
                                    }
                                    placeholder="/orders/{order_id}"
                                    required
                                />
                                <p className="text-sm text-muted-foreground">
                                    {t('api_builder.path_help')}
                                </p>
                                <InputError message={form.errors.path} />
                            </div>
                            {form.data.usage === 'synced' ? (
                                <div className="grid gap-2 md:col-span-2">
                                    <Label>
                                        {t('api_builder.records_path')}
                                    </Label>
                                    <Input
                                        value={form.data.records_path}
                                        onChange={(e) =>
                                            form.setData(
                                                'records_path',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="data.products"
                                    />
                                    <InputError
                                        message={form.errors.records_path}
                                    />
                                </div>
                            ) : null}
                            <div className="grid gap-2">
                                <Label>{t('api_builder.capability')}</Label>
                                <Input
                                    value={form.data.capability}
                                    onChange={(e) =>
                                        form.setData(
                                            'capability',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="check_order_status"
                                />
                                <p className="text-sm text-muted-foreground">
                                    {t('api_builder.capability_help')}
                                </p>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="operation-bot">Attach to bot</Label>
                                <select
                                    id="operation-bot"
                                    className="border-input bg-background ring-offset-background focus-visible:ring-ring flex h-10 w-full rounded-md border px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2"
                                    value={String(form.data.bot ?? '')}
                                    onChange={(event) =>
                                        form.setData('bot', event.target.value)
                                    }
                                >
                                    <option value="">Do not attach to a bot</option>
                                    {bots.map((bot) => (
                                        <option key={bot.id} value={bot.id}>
                                            {bot.name}
                                        </option>
                                    ))}
                                </select>
                                <p className="text-sm text-muted-foreground">
                                    Select the bot that should use this operation.
                                </p>
                                <InputError message={form.errors.bot} />
                            </div>
                        </CardContent>
                    </Card>
                    {templateContext?.botId && form.data.capability ? (
                        <Card>
                            <CardHeader>
                                <CardTitle>
                                    {t('api_builder.capability_mapping')}
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <p className="text-sm text-muted-foreground">
                                    {t('api_builder.capability_mapping_help')}
                                </p>
                                {form.data.input_mapping.map((row, index) => (
                                    <div
                                        key={`mapping-${index}`}
                                        className="grid gap-3 rounded-lg border p-3 md:grid-cols-6"
                                    >
                                        <Input
                                            value={row.model_input}
                                            placeholder={t(
                                                'api_builder.tool_input',
                                            )}
                                            onChange={(event) =>
                                                updateInputMapping(
                                                    index,
                                                    'model_input',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        <Select
                                            value={row.source}
                                            onValueChange={(
                                                value: InputMappingRow['source'],
                                            ) =>
                                                updateInputMapping(
                                                    index,
                                                    'source',
                                                    value,
                                                )
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="model_input">
                                                    {t(
                                                        'api_builder.tool_argument',
                                                    )}
                                                </SelectItem>
                                                <SelectItem value="dataset_field">
                                                    {t(
                                                        'api_builder.dataset_field',
                                                    )}
                                                </SelectItem>
                                                <SelectItem value="context_value">
                                                    {t(
                                                        'api_builder.context_value',
                                                    )}
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                        {row.source === 'dataset_field' ? (
                                            <Input
                                                value={row.dataset_field}
                                                placeholder={t(
                                                    'api_builder.dataset_field_key',
                                                )}
                                                onChange={(event) =>
                                                    updateInputMapping(
                                                        index,
                                                        'dataset_field',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        ) : row.source === 'context_value' ? (
                                            <Input
                                                value={row.context_key}
                                                placeholder={t(
                                                    'api_builder.context_key',
                                                )}
                                                onChange={(event) =>
                                                    updateInputMapping(
                                                        index,
                                                        'context_key',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        ) : (
                                            <div />
                                        )}
                                        <Input
                                            value={row.operation_argument}
                                            placeholder={t(
                                                'api_builder.api_argument',
                                            )}
                                            onChange={(event) =>
                                                updateInputMapping(
                                                    index,
                                                    'operation_argument',
                                                    event.target.value,
                                                )
                                            }
                                        />
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            aria-label={t('common.remove')}
                                            onClick={() =>
                                                form.setData(
                                                    'input_mapping',
                                                    form.data.input_mapping.filter(
                                                        (_, i) => i !== index,
                                                    ),
                                                )
                                            }
                                        >
                                            <Trash2 />
                                        </Button>
                                    </div>
                                ))}
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() =>
                                        form.setData('input_mapping', [
                                            ...form.data.input_mapping,
                                            emptyInputMapping(),
                                        ])
                                    }
                                >
                                    <Plus /> {t('api_builder.add_mapping')}
                                </Button>
                            </CardContent>
                        </Card>
                    ) : null}
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {t('api_builder.operation_headers')}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <p className="text-sm text-muted-foreground">
                                {t('api_builder.operation_headers_help')}
                            </p>
                            {form.data.headers.map((row, index) => (
                                <div
                                    key={`header-${index}`}
                                    className="flex gap-2"
                                >
                                    <Input
                                        value={row.name}
                                        placeholder={t(
                                            'api_builder.header_name',
                                        )}
                                        onChange={(event) =>
                                            updateHeader(
                                                index,
                                                'name',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <Input
                                        value={row.value}
                                        placeholder={t(
                                            'api_builder.header_value',
                                        )}
                                        onChange={(event) =>
                                            updateHeader(
                                                index,
                                                'value',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label={t('common.remove')}
                                        onClick={() =>
                                            form.setData(
                                                'headers',
                                                form.data.headers.filter(
                                                    (_, i) => i !== index,
                                                ),
                                            )
                                        }
                                    >
                                        <Trash2 />
                                    </Button>
                                </div>
                            ))}
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() =>
                                    form.setData('headers', [
                                        ...form.data.headers,
                                        emptyKeyValue(),
                                    ])
                                }
                            >
                                <Plus /> {t('api_builder.add_header')}
                            </Button>
                        </CardContent>
                    </Card>
                    {form.data.usage === 'live_read' ? (
                        <Card>
                            <CardHeader><CardTitle>Live search mappings</CardTitle></CardHeader>
                            <CardContent className="space-y-4">
                                <div className="grid gap-2 md:max-w-xl">
                                    <Label>Remote search text parameter</Label>
                                    <Input value={form.data.live_query.search_text} placeholder="q, query, keyword..." onChange={(event) => form.setData('live_query', { ...form.data.live_query, search_text: event.target.value })} />
                                    <p className="text-sm text-muted-foreground">Choose the remote parameter that receives a customer’s text search. Leave blank to search mapped fields locally.</p>
                                </div>
                                <div className="space-y-3">
                                    <div className="flex items-center justify-between"><div><p className="font-medium">Remote filter mappings</p><p className="text-sm text-muted-foreground">Map a safe field and operator to the API’s parameter.</p></div><Button type="button" variant="outline" size="sm" onClick={() => form.setData('live_query', { ...form.data.live_query, filters: [...form.data.live_query.filters, { field: '', operator: 'eq', remote: '' }] })}><Plus /> Add mapping</Button></div>
                                    {form.data.live_query.filters.map((filter, index) => <div key={`live-filter-${index}`} className="grid gap-3 rounded-lg border p-3 md:grid-cols-4"><Input value={filter.field} placeholder="Safe field key" onChange={(event) => form.setData('live_query', { ...form.data.live_query, filters: form.data.live_query.filters.map((item, itemIndex) => itemIndex === index ? { ...item, field: event.target.value } : item) })} /><Select value={filter.operator} onValueChange={(value) => form.setData('live_query', { ...form.data.live_query, filters: form.data.live_query.filters.map((item, itemIndex) => itemIndex === index ? { ...item, operator: value } : item) })}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent>{['eq', 'neq', 'contains', 'gt', 'gte', 'lt', 'lte', 'between', 'in'].map((operator) => <SelectItem key={operator} value={operator}>{operator}</SelectItem>)}</SelectContent></Select><Input value={filter.remote} placeholder="Remote parameter" onChange={(event) => form.setData('live_query', { ...form.data.live_query, filters: form.data.live_query.filters.map((item, itemIndex) => itemIndex === index ? { ...item, remote: event.target.value } : item) })} /><Button type="button" variant="ghost" size="icon" onClick={() => form.setData('live_query', { ...form.data.live_query, filters: form.data.live_query.filters.filter((_, itemIndex) => itemIndex !== index) })}><Trash2 /></Button></div>)}
                                </div>
                            </CardContent>
                        </Card>
                    ) : null}
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {t('api_builder.request_mapping')}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-6">
                            {(
                                ['query_parameters', 'body_parameters'] as const
                            ).map((section) => (
                                <div key={section} className="space-y-3">
                                    <div className="flex items-center justify-between gap-3">
                                        <div>
                                            <p className="font-medium">
                                                {section === 'query_parameters'
                                                    ? t(
                                                          'api_builder.query_parameters',
                                                      )
                                                    : t(
                                                          'api_builder.body_fields',
                                                      )}
                                            </p>
                                            <p className="text-sm text-muted-foreground">
                                                {t('api_builder.mapping_help')}
                                            </p>
                                        </div>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                addParameter(section)
                                            }
                                        >
                                            <Plus />{' '}
                                            {t('api_builder.add_mapping')}
                                        </Button>
                                    </div>
                                    {form.data[section].map((row, index) => (
                                        <div
                                            key={`${section}-${index}`}
                                            className="grid gap-3 rounded-lg border p-3 md:grid-cols-6"
                                        >
                                            <Input
                                                className="md:col-span-2"
                                                value={row.name}
                                                placeholder={t(
                                                    'api_builder.parameter_path',
                                                )}
                                                onChange={(event) =>
                                                    updateParameter(
                                                        section,
                                                        index,
                                                        'name',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                            <Select
                                                value={row.source}
                                                onValueChange={(
                                                    value: ParameterRow['source'],
                                                ) =>
                                                    updateParameter(
                                                        section,
                                                        index,
                                                        'source',
                                                        value,
                                                    )
                                                }
                                            >
                                                <SelectTrigger>
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="tool_argument">
                                                        {t(
                                                            'api_builder.tool_argument',
                                                        )}
                                                    </SelectItem>
                                                    <SelectItem value="fixed">
                                                        {t(
                                                            'api_builder.fixed_value',
                                                        )}
                                                    </SelectItem>
                                                </SelectContent>
                                            </Select>
                                            {row.source === 'fixed' ? (
                                                <Input
                                                    value={row.value}
                                                    placeholder={t(
                                                        'api_builder.configured_value',
                                                    )}
                                                    onChange={(event) =>
                                                        updateParameter(
                                                            section,
                                                            index,
                                                            'value',
                                                            event.target.value,
                                                        )
                                                    }
                                                />
                                            ) : (
                                                <Input
                                                    value={row.argument}
                                                    placeholder={t(
                                                        'api_builder.argument_name',
                                                    )}
                                                    onChange={(event) =>
                                                        updateParameter(
                                                            section,
                                                            index,
                                                            'argument',
                                                            event.target.value,
                                                        )
                                                    }
                                                />
                                            )}
                                            <Select
                                                value={row.type}
                                                onValueChange={(
                                                    value: ParameterRow['type'],
                                                ) =>
                                                    updateParameter(
                                                        section,
                                                        index,
                                                        'type',
                                                        value,
                                                    )
                                                }
                                            >
                                                <SelectTrigger>
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="string">
                                                        String
                                                    </SelectItem>
                                                    <SelectItem value="integer">
                                                        Integer
                                                    </SelectItem>
                                                    <SelectItem value="number">
                                                        Number
                                                    </SelectItem>
                                                    <SelectItem value="boolean">
                                                        Boolean
                                                    </SelectItem>
                                                </SelectContent>
                                            </Select>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                aria-label={t('common.remove')}
                                                onClick={() =>
                                                    removeParameter(
                                                        section,
                                                        index,
                                                    )
                                                }
                                            >
                                                <Trash2 />
                                            </Button>
                                            {row.source === 'tool_argument' ? (
                                                <label className="flex items-center gap-2 text-sm md:col-span-6">
                                                    <input
                                                        type="checkbox"
                                                        checked={row.required}
                                                        onChange={(event) =>
                                                            updateParameter(
                                                                section,
                                                                index,
                                                                'required',
                                                                event.target
                                                                    .checked,
                                                            )
                                                        }
                                                    />
                                                    {t(
                                                        'api_builder.required_argument',
                                                    )}
                                                </label>
                                            ) : null}
                                        </div>
                                    ))}
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {t('api_builder.response_mapping')}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-5">
                            {form.data.usage === 'synced' ? (
                                <div className="grid gap-2 md:max-w-sm">
                                    <Label>
                                        {t('api_builder.pagination_strategy')}
                                    </Label>
                                    <Select
                                        value={String(
                                            form.data.pagination.type ?? 'none',
                                        )}
                                        onValueChange={(value) =>
                                            form.setData('pagination', {
                                                ...form.data.pagination,
                                                type: value,
                                            })
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="none">
                                                {t('api_builder.no_pagination')}
                                            </SelectItem>
                                            <SelectItem value="page">
                                                {t(
                                                    'api_builder.page_pagination',
                                                )}
                                            </SelectItem>
                                            <SelectItem value="next_url">
                                                {t(
                                                    'api_builder.next_url_pagination',
                                                )}
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            ) : (
                                <div className="space-y-3">
                                    <div className="grid gap-2 md:max-w-sm">
                                        <Label>Response shape</Label>
                                        <Select
                                            value={responseMode}
                                            onValueChange={(value: 'object' | 'collection') =>
                                                setResponseMode(value)
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="object">Single object</SelectItem>
                                                <SelectItem value="collection">Product list</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    {responseMode === 'collection' ? (
                                        <div className="space-y-3 rounded-lg border p-3">
                                            <div className="grid gap-2">
                                                <Label>Collection response path</Label>
                                                <Input
                                                    value={collectionPath}
                                                    placeholder="data"
                                                    onChange={(event) =>
                                                        setCollectionPath(event.target.value)
                                                    }
                                                />
                                                <p className="text-sm text-muted-foreground">
                                                    Path to the array containing the products.
                                                </p>
                                            </div>
                                            {collectionFields.map((field, index) => (
                                                <div
                                                    key={"collection-" + index}
                                                    className="grid gap-3 rounded-lg border p-3 md:grid-cols-6"
                                                >
                                                    <Input
                                                        value={field.name}
                                                        placeholder="Output name (title, price...)"
                                                        onChange={(event) =>
                                                            setCollectionFields((current) =>
                                                                current.map((item, itemIndex) =>
                                                                    itemIndex === index
                                                                        ? { ...item, name: event.target.value }
                                                                        : item,
                                                                ),
                                                            )
                                                        }
                                                    />
                                                    <Select value={field.type} onValueChange={(value: FieldType) => setCollectionFields((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, type: value } : item))}>
                                                        <SelectTrigger><SelectValue placeholder="Type" /></SelectTrigger>
                                                        <SelectContent>{(['string', 'integer', 'decimal', 'boolean', 'date', 'datetime'] as FieldType[]).map((type) => <SelectItem key={type} value={type}>{type}</SelectItem>)}</SelectContent>
                                                    </Select>
                                                    <div className="flex flex-wrap items-center gap-3 text-xs md:col-span-4">
                                                        {(['searchable', 'filterable', 'sortable', 'displayable'] as const).map((key) => <label key={key} className="flex items-center gap-1"><input type="checkbox" checked={field[key]} onChange={(event) => setCollectionFields((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, [key]: event.target.checked } : item))} />{key}</label>)}
                                                    </div>
                                                    <Input
                                                        className="md:col-span-2"
                                                        value={field.path}
                                                        placeholder="Item response path (name, price...)"
                                                        onChange={(event) =>
                                                            setCollectionFields((current) =>
                                                                current.map((item, itemIndex) =>
                                                                    itemIndex === index
                                                                        ? { ...item, path: event.target.value }
                                                                        : item,
                                                                ),
                                                            )
                                                        }
                                                    />
                                                    <label className="flex items-center gap-2 text-sm">
                                                        <input
                                                            type="checkbox"
                                                            checked={field.required}
                                                            onChange={(event) =>
                                                                setCollectionFields((current) =>
                                                                    current.map((item, itemIndex) =>
                                                                        itemIndex === index
                                                                            ? {
                                                                                  ...item,
                                                                                  required:
                                                                                      event.target.checked,
                                                                              }
                                                                            : item,
                                                                    ),
                                                                )
                                                            }
                                                        />
                                                        {t('common.required')}
                                                    </label>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon"
                                                        onClick={() =>
                                                            setCollectionFields((current) =>
                                                                current.filter(
                                                                    (_, itemIndex) =>
                                                                        itemIndex !== index,
                                                                ),
                                                            )
                                                        }
                                                    >
                                                        <Trash2 />
                                                    </Button>
                                                </div>
                                            ))}
                                            <Button
                                                type="button"
                                                variant="outline"
                                                onClick={() =>
                                                    setCollectionFields((current) => [
                                                        ...current,
                                                        emptyResponseField(),
                                                    ])
                                                }
                                            >
                                                <Plus /> Add product field
                                            </Button>
                                        </div>
                                    ) : null}
                                    {responseMode === 'object' ? (
                                    <>
                                    <div className="flex items-center justify-between gap-3">
                                        <div>
                                            <p className="font-medium">
                                                {t(
                                                    'api_builder.safe_output_fields',
                                                )}
                                            </p>
                                            <p className="text-sm text-muted-foreground">
                                                {t('api_builder.output_help')}
                                            </p>
                                        </div>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                form.setData(
                                                    'response_fields',
                                                    [
                                                        ...form.data
                                                            .response_fields,
                                                        emptyResponseField(),
                                                    ],
                                                )
                                            }
                                        >
                                            <Plus />{' '}
                                            {t('api_builder.add_mapping')}
                                        </Button>
                                    </div>
                                    {form.data.response_fields.map(
                                        (field, index) => (
                                                <div
                                                key={`response-${index}`}
                                                className="grid gap-3 rounded-lg border p-3 md:grid-cols-6"
                                            >
                                                <Input
                                                    value={field.name}
                                                    placeholder={t(
                                                        'api_builder.output_name',
                                                    )}
                                                    onChange={(event) =>
                                                        updateResponseField(
                                                            index,
                                                            'name',
                                                            event.target.value,
                                                        )
                                                    }
                                                />
                                                <Select value={field.type} onValueChange={(value: FieldType) => updateResponseField(index, 'type', value)}>
                                                    <SelectTrigger><SelectValue placeholder="Type" /></SelectTrigger>
                                                    <SelectContent>{(['string', 'integer', 'decimal', 'boolean', 'date', 'datetime'] as FieldType[]).map((type) => <SelectItem key={type} value={type}>{type}</SelectItem>)}</SelectContent>
                                                </Select>
                                                <div className="flex flex-wrap items-center gap-3 text-xs md:col-span-4">
                                                    {(['searchable', 'filterable', 'sortable', 'displayable'] as const).map((key) => <label key={key} className="flex items-center gap-1"><input type="checkbox" checked={field[key]} onChange={(event) => updateResponseField(index, key, event.target.checked)} />{key}</label>)}
                                                </div>
                                                <Input
                                                    className="md:col-span-2"
                                                    value={field.path}
                                                    placeholder={t(
                                                        'api_builder.response_path',
                                                    )}
                                                    onChange={(event) =>
                                                        updateResponseField(
                                                            index,
                                                            'path',
                                                            event.target.value,
                                                        )
                                                    }
                                                />
                                                <label className="flex items-center gap-2 text-sm">
                                                    <input
                                                        type="checkbox"
                                                        checked={field.required}
                                                        onChange={(event) =>
                                                            updateResponseField(
                                                                index,
                                                                'required',
                                                                event.target
                                                                    .checked,
                                                            )
                                                        }
                                                    />
                                                    {t('common.required')}
                                                </label>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={t(
                                                        'common.remove',
                                                    )}
                                                    onClick={() =>
                                                        form.setData(
                                                            'response_fields',
                                                            form.data.response_fields.filter(
                                                                (_, i) =>
                                                                    i !== index,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    <Trash2 />
                                                </Button>
                                            </div>
                                        ),
                                    )}
                                    </>
                                    ) : null}
                                </div>
                            )}
                            {pathArguments.length > 0 ? (
                                <div className="space-y-3">
                                    <p className="font-medium">
                                        {t('api_builder.path_test_values')}
                                    </p>
                                    <div className="grid gap-4 md:grid-cols-2">
                                        {pathArguments.map((argument) => (
                                            <div
                                                key={argument}
                                                className="grid gap-2"
                                            >
                                                <Label>{argument}</Label>
                                                <Input
                                                    value={String(
                                                        (
                                                            form.data
                                                                .test_arguments as Record<
                                                                string,
                                                                unknown
                                                            >
                                                        )[argument] ?? '',
                                                    )}
                                                    onChange={(event) =>
                                                        form.setData(
                                                            'test_arguments',
                                                            {
                                                                ...form.data
                                                                    .test_arguments,
                                                                [argument]:
                                                                    event.target
                                                                        .value,
                                                            },
                                                        )
                                                    }
                                                />
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            ) : null}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {t('api_builder.test_request')}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <p className="text-sm text-muted-foreground">
                                {form.data.usage === 'live_write'
                                    ? t('api_builder.write_test_help')
                                    : t('api_builder.test_request_help')}
                            </p>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={runTest}
                                disabled={testing}
                            >
                                <FlaskConical />
                                {testing
                                    ? t('common.loading')
                                    : form.data.usage === 'live_write'
                                      ? t('api_builder.preview_request')
                                      : t('api_builder.test_request')}
                            </Button>
                            {preview ? (
                                <pre className="max-h-72 overflow-auto rounded-lg border bg-muted/20 p-4 text-xs">
                                    {JSON.stringify(preview, null, 2)}
                                </pre>
                            ) : null}
                        </CardContent>
                    </Card>
                    <div className="flex gap-3">
                        <Button type="submit" disabled={form.processing}>
                            {form.processing
                                ? t('common.saving')
                                : t('api_builder.save_operation')}
                        </Button>
                        <Button variant="ghost" asChild>
                            <Link
                                href={create.url([
                                    currentTeamSlug,
                                    dataSource.id,
                                ])}
                            >
                                {t('common.cancel')}
                            </Link>
                        </Button>
                    </div>
                </form>
            </div>
        </>
    );
}
