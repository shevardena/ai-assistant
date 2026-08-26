import { Form, Link } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { index } from '@/routes/data-sources';
import type { DataSource, DataSourceType } from '@/types';
import type { RouteFormDefinition } from '@/wayfinder';

type Props = {
    action: RouteFormDefinition<'post'>;
    dataSource?: DataSource;
    currentTeamSlug: string;
    submitLabel: string;
    sourceType?: DataSourceType;
};

function configValue(dataSource?: DataSource): string {
    return JSON.stringify(dataSource?.config ?? {}, null, 2);
}

export default function DataSourceForm({
    action,
    dataSource,
    currentTeamSlug,
    submitLabel,
    sourceType,
}: Props) {
    const { t } = useTranslation();
    const [selectedSourceType, setSelectedSourceType] =
        useState<DataSourceType>(dataSource?.type ?? sourceType ?? 'file');

    return (
        <Form
            {...action}
            options={{ preserveScroll: true }}
            className="space-y-6"
        >
            {({ errors, processing }) => (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="name">{t('common.name')}</Label>
                        <Input
                            id="name"
                            name="name"
                            defaultValue={dataSource?.name ?? ''}
                            placeholder="Product catalog"
                            required
                            autoFocus
                            data-test="data-source-name-input"
                        />
                        <InputError message={errors.name} />
                    </div>

                    {sourceType ? (
                        <input type="hidden" name="type" value={sourceType} />
                    ) : (
                        <div className="grid gap-2">
                            <Label htmlFor="type">{t('common.type')}</Label>
                            <Select
                                name="type"
                                value={selectedSourceType}
                                onValueChange={(value) =>
                                    setSelectedSourceType(
                                        value as DataSourceType,
                                    )
                                }
                            >
                                <SelectTrigger
                                    id="type"
                                    className="w-full"
                                    data-test="data-source-type-select"
                                >
                                    <SelectValue
                                        placeholder={t(
                                            'common.select_source_type',
                                        )}
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="file">
                                        {t('common.uploaded_file')}
                                    </SelectItem>
                                    <SelectItem value="rest_api">
                                        {t('common.rest_api')}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <p className="text-sm text-muted-foreground">
                                {t('data_source_chooser.file_help')}
                            </p>
                            <InputError message={errors.type} />
                        </div>
                    )}

                    <details className="rounded-xl border px-4 py-3">
                        <summary className="cursor-pointer font-medium">
                            {t('data_source_chooser.advanced')}
                        </summary>
                        <div className="mt-4 grid gap-2">
                            <p className="text-sm text-muted-foreground">
                                {t('data_source_chooser.advanced_help')}
                            </p>
                            <Label htmlFor="config">
                                {t('data_source_chooser.advanced')}
                            </Label>
                            <textarea
                                id="config"
                                name="config"
                                defaultValue={configValue(dataSource)}
                                placeholder="{}"
                                rows={7}
                                spellCheck={false}
                                className="flex min-h-32 w-full rounded-md border border-input bg-transparent px-3 py-2 font-mono text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                                data-test="data-source-config-input"
                            />
                            <InputError message={errors.config} />
                        </div>
                    </details>

                    <div className="flex items-center gap-4">
                        <Button
                            type="submit"
                            disabled={processing}
                            data-test="data-source-save-button"
                        >
                            {processing ? t('common.saving') : submitLabel}
                        </Button>
                        <Button variant="ghost" asChild>
                            <Link href={index(currentTeamSlug).url}>
                                {t('common.cancel')}
                            </Link>
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
