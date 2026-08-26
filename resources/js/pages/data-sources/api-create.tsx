import { Head, Link, useForm, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    CheckCircle2,
    FlaskConical,
    Plus,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
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
import {
    create,
    store,
    test as testConnection,
    update,
} from '@/routes/data-sources/api';

type Row = { name: string; value: string };
type Props = {
    dataSource?: {
        id: number;
        name: string;
        type: 'rest_api';
        config: Record<string, unknown>;
    };
    templateContext?: {
        templateKey: string;
        requirementKey: string;
        titleKey: string;
        type: string;
        dataMode: string | null;
        capabilities: string[];
        suggestedFields: string[];
        botId?: number | null;
    } | null;
    authTypes: { value: string; labelKey: string }[];
};

const emptyRow = (): Row => ({ name: '', value: '' });

function rowsFrom(value: unknown): Row[] {
    if (!value || typeof value !== 'object' || Array.isArray(value)) {
        return [emptyRow()];
    }

    const rows = Object.entries(value).map(([name, item]) => ({
        name,
        value: String(item ?? ''),
    }));

    return rows.length ? rows : [emptyRow()];
}

export default function ApiConnectionCreate({
    dataSource,
    templateContext,
    authTypes,
}: Props) {
    const { t } = useTranslation();
    const { currentTeam } = usePage().props;
    const config = dataSource?.config ?? {};
    const form = useForm({
        name: dataSource?.name ?? '',
        base_url: String(config.base_url ?? ''),
        auth_type: String(config.auth_type ?? 'none'),
        api_key_placement: String(config.api_key_placement ?? 'header'),
        api_key_name: String(
            config.api_key_name ?? config.api_key_header ?? 'X-API-Key',
        ),
        custom_header_name: String(config.custom_header_name ?? ''),
        bearer_token: '',
        api_key: '',
        basic_username: '',
        basic_password: '',
        custom_header_value: '',
        default_headers: rowsFrom(config.default_headers),
        default_query_parameters: rowsFrom(config.default_query_parameters),
        template: templateContext?.templateKey ?? '',
        requirement: templateContext?.requirementKey ?? '',
        capability: templateContext?.capabilities[0] ?? '',
        bot: templateContext?.botId ?? '',
    });
    const [advancedJson, setAdvancedJson] = useState(
        JSON.stringify(config, null, 2),
    );
    const [tested, setTested] = useState<Record<string, unknown> | null>(null);
    const [testing, setTesting] = useState(false);
    const [headers, setHeaders] = useState(rowsFrom(config.default_headers));
    const [queryParameters, setQueryParameters] = useState(
        rowsFrom(config.default_query_parameters),
    );
    const isEditing = Boolean(dataSource);
    const currentTeamSlug = currentTeam?.slug;

    const credentialsFor = (data: typeof form.data) => ({
        bearer_token: data.auth_type === 'bearer' ? data.bearer_token : '',
        api_key: data.auth_type === 'api_key' ? data.api_key : '',
        basic_username: data.auth_type === 'basic' ? data.basic_username : '',
        basic_password: data.auth_type === 'basic' ? data.basic_password : '',
        custom_header_value:
            data.auth_type === 'custom_header'
                ? data.custom_header_value
                : '',
    });

    if (!currentTeamSlug) {
        return null;
    }

    const updateRows = (
        kind: 'headers' | 'query',
        index: number,
        key: keyof Row,
        value: string,
    ) => {
        const setter = kind === 'headers' ? setHeaders : setQueryParameters;
        const current = kind === 'headers' ? headers : queryParameters;
        setter(
            current.map((row, rowIndex) =>
                rowIndex === index ? { ...row, [key]: value } : row,
            ),
        );
    };

    const runTest = async () => {
        setTesting(true);
        form.clearErrors();

        try {
            const response = await fetch(testConnection.url(currentTeamSlug), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-TOKEN':
                        document
                            .querySelector('meta[name="csrf-token"]')
                            ?.getAttribute('content') ?? '',
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    ...form.data,
                    ...credentialsFor(form.data),
                    default_headers: Object.fromEntries(
                        headers
                            .filter((row) => row.name)
                            .map((row) => [row.name, row.value]),
                    ),
                    default_query_parameters: Object.fromEntries(
                        queryParameters
                            .filter((row) => row.name)
                            .map((row) => [row.name, row.value]),
                    ),
                }),
            });
            const payload = await response.json();

            if (!response.ok) {
                throw new Error(
                    payload.message ?? t('api_builder.connection_failed'),
                );
            }

            setTested(payload);
        } catch (error) {
            form.setError(
                'base_url',
                error instanceof Error
                    ? error.message
                    : t('api_builder.connection_failed'),
            );
        } finally {
            setTesting(false);
        }
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        let advancedConfig: Record<string, unknown> = {};

        if (advancedJson.trim() !== '') {
            try {
                const parsed = JSON.parse(advancedJson);

                if (
                    !parsed ||
                    typeof parsed !== 'object' ||
                    Array.isArray(parsed)
                ) {
                    throw new Error(t('data_source_chooser.invalid_json'));
                }

                advancedConfig = parsed as Record<string, unknown>;
            } catch (error) {
                form.setError(
                    'advanced_config' as never,
                    error instanceof Error
                        ? error.message
                        : t('api_builder.connection_failed'),
                );

                return;
            }
        }

        form.transform((data) => ({
            ...data,
            ...credentialsFor(data),
            advanced_config: advancedConfig,
            default_headers: Object.fromEntries(
                headers
                    .filter((row) => row.name)
                    .map((row) => [row.name, row.value]),
            ),
            default_query_parameters: Object.fromEntries(
                queryParameters
                    .filter((row) => row.name)
                    .map((row) => [row.name, row.value]),
            ),
        }));

        if (dataSource) {
            form.patch(update.url([currentTeamSlug, dataSource.id]));
        } else {
            form.post(store.url(currentTeamSlug));
        }
    };

    return (
        <>
            <Head title={t('api_builder.connection_title')} />
            <div className="max-w-5xl space-y-6 p-4 md:p-6">
                <div className="flex items-start gap-3">
                    <Button variant="ghost" size="icon" asChild>
                        <Link
                            href={create.url(currentTeamSlug)}
                            aria-label={t('common.back')}
                        >
                            <ArrowLeft />
                        </Link>
                    </Button>
                    <Heading
                        variant="small"
                        title={t('api_builder.connection_title')}
                        description={t('api_builder.connection_description')}
                    />
                </div>
                {templateContext ? (
                    <Card className="border-primary/30 bg-primary/5">
                        <CardContent className="flex items-center gap-3 p-4">
                            <Badge>{t('api_builder.context')}</Badge>
                            <span>
                                {t('api_builder.context_for')}{' '}
                                <strong>{t(templateContext.titleKey)}</strong>
                            </span>
                        </CardContent>
                    </Card>
                ) : null}
                <form onSubmit={submit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {t('api_builder.connection_step')}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-5 md:grid-cols-2">
                            <div className="grid gap-2 md:col-span-2">
                                <Label htmlFor="name">{t('common.name')}</Label>
                                <Input
                                    id="name"
                                    value={form.data.name}
                                    onChange={(e) =>
                                        form.setData('name', e.target.value)
                                    }
                                    required
                                />
                                <InputError message={form.errors.name} />
                            </div>
                            <div className="grid gap-2 md:col-span-2">
                                <Label htmlFor="base_url">
                                    {t('api_builder.base_url')}
                                </Label>
                                <Input
                                    id="base_url"
                                    type="url"
                                    value={form.data.base_url}
                                    onChange={(e) =>
                                        form.setData('base_url', e.target.value)
                                    }
                                    placeholder="https://api.example.com"
                                    required
                                />
                                <InputError message={form.errors.base_url} />
                            </div>
                            <div className="grid gap-2 md:col-span-2">
                                <Label htmlFor="auth_type">
                                    {t('api_builder.authentication')}
                                </Label>
                                <Select
                                    value={form.data.auth_type}
                                    onValueChange={(value) =>
                                        form.setData('auth_type', value)
                                    }
                                >
                                    <SelectTrigger id="auth_type">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {authTypes.map((item) => (
                                            <SelectItem
                                                key={item.value}
                                                value={item.value}
                                            >
                                                {t(item.labelKey)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            {form.data.auth_type === 'bearer' ? (
                                <div className="grid gap-2 md:col-span-2">
                                    <Label htmlFor="bearer_token">
                                        {t('api_builder.token')}
                                    </Label>
                                    <Input
                                        id="bearer_token"
                                        name="bearer_token"
                                        type="password"
                                        value={form.data.bearer_token}
                                        onChange={(e) =>
                                            form.setData(
                                                'bearer_token',
                                                e.target.value,
                                            )
                                        }
                                        placeholder={
                                            isEditing
                                                ? t(
                                                      'api_builder.configured_placeholder',
                                                  )
                                                : ''
                                        }
                                    />
                                </div>
                            ) : null}
                            {form.data.auth_type === 'api_key' ? (
                                <>
                                    <div className="grid gap-2">
                                        <Label>
                                            {t('api_builder.key_name')}
                                        </Label>
                                        <Input
                                            value={form.data.api_key_name}
                                            onChange={(e) =>
                                                form.setData(
                                                    'api_key_name',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label>
                                            {t('api_builder.key_placement')}
                                        </Label>
                                        <Select
                                            value={form.data.api_key_placement}
                                            onValueChange={(value) =>
                                                form.setData(
                                                    'api_key_placement',
                                                    value,
                                                )
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="header">
                                                    {t('api_builder.header')}
                                                </SelectItem>
                                                <SelectItem value="query">
                                                    {t(
                                                        'api_builder.query_parameter',
                                                    )}
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="grid gap-2 md:col-span-2">
                                        <Label>
                                            {t('api_builder.key_value')}
                                        </Label>
                                        <Input
                                            type="password"
                                            value={form.data.api_key}
                                            onChange={(e) =>
                                                form.setData(
                                                    'api_key',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder={
                                                isEditing
                                                    ? t(
                                                          'api_builder.configured_placeholder',
                                                      )
                                                    : ''
                                            }
                                        />
                                    </div>
                                </>
                            ) : null}
                            {form.data.auth_type === 'basic' ? (
                                <>
                                    <div className="grid gap-2">
                                        <Label>
                                            {t('api_builder.username')}
                                        </Label>
                                        <Input
                                            value={form.data.basic_username}
                                            onChange={(e) =>
                                                form.setData(
                                                    'basic_username',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label>
                                            {t('api_builder.password')}
                                        </Label>
                                        <Input
                                            type="password"
                                            value={form.data.basic_password}
                                            onChange={(e) =>
                                                form.setData(
                                                    'basic_password',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder={
                                                isEditing
                                                    ? t(
                                                          'api_builder.configured_placeholder',
                                                      )
                                                    : ''
                                            }
                                        />
                                    </div>
                                </>
                            ) : null}
                            {form.data.auth_type === 'custom_header' ? (
                                <>
                                    <div className="grid gap-2">
                                        <Label>
                                            {t('api_builder.header_name')}
                                        </Label>
                                        <Input
                                            value={form.data.custom_header_name}
                                            onChange={(e) =>
                                                form.setData(
                                                    'custom_header_name',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label>
                                            {t('api_builder.header_value')}
                                        </Label>
                                        <Input
                                            type="password"
                                            value={
                                                form.data.custom_header_value
                                            }
                                            onChange={(e) =>
                                                form.setData(
                                                    'custom_header_value',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder={
                                                isEditing
                                                    ? t(
                                                          'api_builder.configured_placeholder',
                                                      )
                                                    : ''
                                            }
                                        />
                                    </div>
                                </>
                            ) : null}
                        </CardContent>
                    </Card>
                    <details className="rounded-xl border px-4 py-3">
                        <summary className="cursor-pointer font-medium">
                            {t('data_source_chooser.advanced')}
                        </summary>
                        <div className="mt-4 grid gap-2">
                            <p className="text-sm text-muted-foreground">
                                {t('data_source_chooser.advanced_help')}
                            </p>
                            <textarea
                                value={advancedJson}
                                onChange={(event) =>
                                    setAdvancedJson(event.target.value)
                                }
                                rows={8}
                                spellCheck={false}
                                className="flex min-h-32 w-full rounded-md border border-input bg-transparent px-3 py-2 font-mono text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                            />
                            <InputError
                                message={
                                    (
                                        form.errors as Record<
                                            string,
                                            string | undefined
                                        >
                                    ).advanced_config
                                }
                            />
                        </div>
                    </details>
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {t('api_builder.default_headers')}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {headers.map((row, index) => (
                                <div className="flex gap-2" key={index}>
                                    <Input
                                        aria-label={t(
                                            'api_builder.header_name',
                                        )}
                                        value={row.name}
                                        onChange={(e) =>
                                            updateRows(
                                                'headers',
                                                index,
                                                'name',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Accept"
                                    />
                                    <Input
                                        aria-label={t(
                                            'api_builder.header_value',
                                        )}
                                        value={row.value}
                                        onChange={(e) =>
                                            updateRows(
                                                'headers',
                                                index,
                                                'value',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="application/json"
                                    />
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        onClick={() =>
                                            setHeaders(
                                                headers.filter(
                                                    (_, rowIndex) =>
                                                        rowIndex !== index,
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
                                    setHeaders([...headers, emptyRow()])
                                }
                            >
                                <Plus />
                                {t('api_builder.add_header')}
                            </Button>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {t('api_builder.default_query')}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {queryParameters.map((row, index) => (
                                <div className="flex gap-2" key={index}>
                                    <Input
                                        aria-label={t(
                                            'api_builder.parameter_name',
                                        )}
                                        value={row.name}
                                        onChange={(e) =>
                                            updateRows(
                                                'query',
                                                index,
                                                'name',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="locale"
                                    />
                                    <Input
                                        aria-label={t(
                                            'api_builder.parameter_value',
                                        )}
                                        value={row.value}
                                        onChange={(e) =>
                                            updateRows(
                                                'query',
                                                index,
                                                'value',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="en"
                                    />
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        onClick={() =>
                                            setQueryParameters(
                                                queryParameters.filter(
                                                    (_, rowIndex) =>
                                                        rowIndex !== index,
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
                                    setQueryParameters([
                                        ...queryParameters,
                                        emptyRow(),
                                    ])
                                }
                            >
                                <Plus />
                                {t('api_builder.add_parameter')}
                            </Button>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {t('api_builder.test_connection')}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <p className="text-sm text-muted-foreground">
                                {t('api_builder.test_connection_help')}
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
                                    : t('api_builder.test_connection')}
                            </Button>
                            {tested ? (
                                <div className="rounded-lg border bg-muted/20 p-4 text-sm">
                                    <div className="flex items-center gap-2">
                                        <CheckCircle2 className="size-4 text-emerald-600" />
                                        {String(tested.status ?? '')} ·{' '}
                                        {t('api_builder.safe_preview')}
                                    </div>
                                    <pre className="mt-3 max-h-72 overflow-auto text-xs">
                                        {JSON.stringify(
                                            tested.response,
                                            null,
                                            2,
                                        )}
                                    </pre>
                                </div>
                            ) : null}
                        </CardContent>
                    </Card>
                    <div className="flex gap-3">
                        <Button type="submit" disabled={form.processing}>
                            {form.processing
                                ? t('common.saving')
                                : t('api_builder.save_connection')}
                        </Button>
                        <Button variant="ghost" asChild>
                            <Link href={create.url(currentTeamSlug)}>
                                {t('common.cancel')}
                            </Link>
                        </Button>
                    </div>
                </form>
            </div>
        </>
    );
}
