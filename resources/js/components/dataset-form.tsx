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
import { index } from '@/routes/datasets';
import type {
    Dataset,
    DatasetDataSourceOption,
    DatasetRetrievalMode,
} from '@/types';
import type { RouteFormDefinition } from '@/wayfinder';

type Props = {
    action: RouteFormDefinition<'post'>;
    dataset?: Dataset;
    dataSources: DatasetDataSourceOption[];
    currentTeamSlug: string;
    submitLabel: string;
};

function settingsValue(dataset?: Dataset): string {
    return JSON.stringify(dataset?.settings ?? {}, null, 2);
}

export default function DatasetForm({
    action,
    dataset,
    dataSources,
    currentTeamSlug,
    submitLabel,
}: Props) {
    const { t } = useTranslation();
    const [dataSourceId, setDataSourceId] = useState(
        dataset?.dataSource ? String(dataset.dataSource.id) : '',
    );
    const [retrievalMode, setRetrievalMode] = useState<DatasetRetrievalMode>(
        dataset?.retrievalMode ?? 'indexed',
    );

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
                            defaultValue={dataset?.name ?? ''}
                            placeholder="Product catalog"
                            required
                            autoFocus
                            data-test="dataset-name-input"
                        />
                        <InputError message={errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="slug">Slug</Label>
                        <Input
                            id="slug"
                            name="slug"
                            defaultValue={dataset?.slug ?? ''}
                            placeholder="product-catalog"
                            required
                            data-test="dataset-slug-input"
                        />
                        <p className="text-sm text-muted-foreground">
                            Unique within the current team.
                        </p>
                        <InputError message={errors.slug} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="data_source_id">
                            {t('common.data_source')}
                        </Label>
                        <Select
                            name="data_source_id"
                            value={dataSourceId}
                            onValueChange={setDataSourceId}
                        >
                            <SelectTrigger
                                id="data_source_id"
                                className="w-full"
                                data-test="dataset-data-source-select"
                            >
                                <SelectValue
                                    placeholder={t('common.select_data_source')}
                                />
                            </SelectTrigger>
                            <SelectContent>
                                {dataSources.map((dataSource) => (
                                    <SelectItem
                                        key={dataSource.id}
                                        value={String(dataSource.id)}
                                    >
                                        {dataSource.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {dataSources.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Create a Data Source before creating a Dataset.
                            </p>
                        ) : null}
                        <InputError message={errors.data_source_id} />
                    </div>

                    <div className="grid gap-2 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="entity_type">Entity type</Label>
                            <Input
                                id="entity_type"
                                name="entity_type"
                                defaultValue={dataset?.entityType ?? 'generic'}
                                placeholder="product"
                                required
                            />
                            <InputError message={errors.entity_type} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="retrieval_mode">
                                Retrieval mode
                            </Label>
                            <Select
                                name="retrieval_mode"
                                value={retrievalMode}
                                onValueChange={(value) =>
                                    setRetrievalMode(
                                        value as DatasetRetrievalMode,
                                    )
                                }
                            >
                                <SelectTrigger
                                    id="retrieval_mode"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="indexed">
                                        Indexed
                                    </SelectItem>
                                    <SelectItem value="live">Live</SelectItem>
                                    <SelectItem value="hybrid">
                                        Hybrid
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError message={errors.retrieval_mode} />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="primary_key_path">
                            Primary key path
                        </Label>
                        <Input
                            id="primary_key_path"
                            name="primary_key_path"
                            defaultValue={dataset?.primaryKeyPath ?? ''}
                            placeholder="id"
                        />
                        <InputError message={errors.primary_key_path} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="settings">Dataset settings</Label>
                        <textarea
                            id="settings"
                            name="settings"
                            defaultValue={settingsValue(dataset)}
                            placeholder={'{\n  "locale": "en"\n}'}
                            rows={6}
                            spellCheck={false}
                            className="flex min-h-28 w-full rounded-md border border-input bg-transparent px-3 py-2 font-mono text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                        />
                        <p className="text-sm text-muted-foreground">
                            Optional JSON configuration. Importing and indexing
                            are handled in later stages.
                        </p>
                        <InputError message={errors.settings} />
                    </div>

                    <div className="flex items-center gap-4">
                        <Button
                            type="submit"
                            disabled={processing || dataSources.length === 0}
                            data-test="dataset-save-button"
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
