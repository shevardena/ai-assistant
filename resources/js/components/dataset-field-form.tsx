import { Form, Link } from '@inertiajs/react';
import { useState } from 'react';
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
import { show } from '@/routes/datasets';
import type { DatasetField, DatasetFieldDataType } from '@/types';
import type { RouteFormDefinition } from '@/wayfinder';

type DatasetReference = {
    id: number;
    name: string;
};

type Props = {
    action: RouteFormDefinition<'post'>;
    dataset: DatasetReference;
    field?: DatasetField;
    currentTeamSlug: string;
    submitLabel: string;
};

const fieldTypes: DatasetFieldDataType[] = [
    'string',
    'integer',
    'decimal',
    'boolean',
    'date',
    'datetime',
    'url',
];

const priceSemanticRoles = ['current_price', 'regular_price', 'discount_percent'] as const;

function compatiblePriceRoles(dataType: DatasetFieldDataType): string[] {
    return dataType === 'decimal'
        ? [...priceSemanticRoles]
        : dataType === 'integer'
            ? ['discount_percent']
            : [];
}

function configValue(field?: DatasetField): string {
    return JSON.stringify(field?.config ?? {}, null, 2);
}

export default function DatasetFieldForm({
    action,
    dataset,
    field,
    currentTeamSlug,
    submitLabel,
}: Props) {
    const [dataType, setDataType] = useState<DatasetFieldDataType>(
        field?.dataType ?? 'string',
    );
    const [flags, setFlags] = useState({
        isSearchable: field?.isSearchable ?? false,
        isFilterable: field?.isFilterable ?? false,
        isSortable: field?.isSortable ?? false,
        isSemantic: field?.isSemantic ?? false,
        isDisplayable: field?.isDisplayable ?? true,
    });

    const setFlag = (name: keyof typeof flags, value: boolean) => {
        setFlags((current) => ({ ...current, [name]: value }));
    };

    return (
        <Form
            {...action}
            options={{ preserveScroll: true }}
            className="space-y-6"
        >
            {({ errors, processing }) => (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="source_path">Source path</Label>
                        <Input
                            id="source_path"
                            name="source_path"
                            defaultValue={field?.sourcePath ?? ''}
                            placeholder="attributes.storage"
                            required
                            autoFocus
                            data-test="dataset-field-source-path-input"
                        />
                        <p className="text-sm text-muted-foreground">
                            Enter a source path manually, such as
                            product.brand.name.
                        </p>
                        <InputError message={errors.source_path} />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="key">Internal key</Label>
                            <Input
                                id="key"
                                name="key"
                                defaultValue={field?.key ?? ''}
                                placeholder="storage_gb"
                                required
                                data-test="dataset-field-key-input"
                            />
                            <InputError message={errors.key} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="label">Label</Label>
                            <Input
                                id="label"
                                name="label"
                                defaultValue={field?.label ?? ''}
                                placeholder="Storage"
                                required
                                data-test="dataset-field-label-input"
                            />
                            <InputError message={errors.label} />
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="canonical_name">
                                Canonical name
                            </Label>
                            <Input
                                id="canonical_name"
                                name="canonical_name"
                                defaultValue={field?.canonicalName ?? ''}
                                placeholder="storage"
                            />
                            <InputError message={errors.canonical_name} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="data_type">Data type</Label>
                            <Select
                                name="data_type"
                                value={dataType}
                                onValueChange={(value) =>
                                    setDataType(value as DatasetFieldDataType)
                                }
                            >
                                <SelectTrigger
                                    id="data_type"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {fieldTypes.map((type) => (
                                        <SelectItem key={type} value={type}>
                                            {type}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.data_type} />
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="semantic_type">Semantic type</Label>
                            <Input
                                id="semantic_type"
                                name="semantic_type"
                                defaultValue={field?.semanticType ?? ''}
                                placeholder="price"
                            />
                            <InputError message={errors.semantic_type} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="price_semantic_role">Price semantic role</Label>
                            <Select
                                value={compatiblePriceRoles(dataType).includes(field?.semanticType ?? '') ? field?.semanticType ?? 'none' : 'none'}
                                onValueChange={(value) => {
                                    const input = document.getElementById('semantic_type') as HTMLInputElement | null;

                                    if (input) {
                                        input.value = value === 'none' ? '' : value;
                                    }
                                }}
                            >
                                <SelectTrigger id="price_semantic_role" className="w-full"><SelectValue placeholder="None" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">None</SelectItem>
                                    {compatiblePriceRoles(dataType).map((role) => <SelectItem key={role} value={role}>{role}</SelectItem>)}
                                </SelectContent>
                            </Select>
                            <p className="text-xs text-muted-foreground">Available only for numeric fields.</p>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="normalizer">Normalizer</Label>
                            <Input
                                id="normalizer"
                                name="normalizer"
                                defaultValue={field?.normalizer ?? ''}
                                placeholder="lowercase"
                            />
                            <InputError message={errors.normalizer} />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="description">Description</Label>
                        <textarea
                            id="description"
                            name="description"
                            defaultValue={field?.description ?? ''}
                            rows={3}
                            className="flex min-h-20 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                        />
                        <InputError message={errors.description} />
                    </div>

                    <div className="grid gap-3 rounded-lg border p-4">
                        <p className="text-sm font-medium">
                            Schema declarations
                        </p>
                        <div className="grid gap-3 sm:grid-cols-2">
                            {(
                                [
                                    [
                                        'isSearchable',
                                        'is_searchable',
                                        'Searchable',
                                    ],
                                    [
                                        'isFilterable',
                                        'is_filterable',
                                        'Filterable',
                                    ],
                                    ['isSortable', 'is_sortable', 'Sortable'],
                                    ['isSemantic', 'is_semantic', 'Semantic'],
                                    [
                                        'isDisplayable',
                                        'is_displayable',
                                        'Displayable',
                                    ],
                                ] as const
                            ).map(([stateName, inputName, label]) => (
                                <label
                                    key={inputName}
                                    className="flex items-center gap-2 text-sm"
                                >
                                    <input
                                        type="hidden"
                                        name={inputName}
                                        value={flags[stateName] ? '1' : '0'}
                                    />
                                    <input
                                        type="checkbox"
                                        checked={flags[stateName]}
                                        onChange={(event) =>
                                            setFlag(
                                                stateName,
                                                event.target.checked,
                                            )
                                        }
                                        className="size-4 rounded border-input accent-primary"
                                    />
                                    {label}
                                </label>
                            ))}
                        </div>
                        {errors.is_searchable ||
                        errors.is_filterable ||
                        errors.is_sortable ||
                        errors.is_semantic ||
                        errors.is_displayable ? (
                            <InputError
                                message={
                                    errors.is_searchable ??
                                    errors.is_filterable ??
                                    errors.is_sortable ??
                                    errors.is_semantic ??
                                    errors.is_displayable
                                }
                            />
                        ) : null}
                    </div>

                    <div className="grid gap-2 sm:max-w-xs">
                        <Label htmlFor="position">Position</Label>
                        <Input
                            id="position"
                            name="position"
                            type="number"
                            min={0}
                            defaultValue={field?.position ?? 0}
                            required
                        />
                        <InputError message={errors.position} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="config">Field configuration</Label>
                        <textarea
                            id="config"
                            name="config"
                            defaultValue={configValue(field)}
                            placeholder={'{\n  "unit": "gb"\n}'}
                            rows={5}
                            spellCheck={false}
                            className="flex min-h-24 w-full rounded-md border border-input bg-transparent px-3 py-2 font-mono text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                        />
                        <InputError message={errors.config} />
                    </div>

                    <div className="flex items-center gap-4">
                        <Button
                            type="submit"
                            disabled={processing}
                            data-test="dataset-field-save-button"
                        >
                            {processing ? 'Saving...' : submitLabel}
                        </Button>
                        <Button variant="ghost" asChild>
                            <Link
                                href={show([currentTeamSlug, dataset.id]).url}
                            >
                                Cancel
                            </Link>
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
