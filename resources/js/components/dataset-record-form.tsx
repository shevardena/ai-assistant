import { Form, Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { DatasetRecord, DatasetRecordFieldDefinition } from '@/types';
import type { RouteFormDefinition } from '@/wayfinder';

type Props = {
    action: RouteFormDefinition<'post' | 'put' | 'patch'>;
    fields: DatasetRecordFieldDefinition[];
    record?: DatasetRecord;
    cancelUrl: string;
    submitLabel: string;
};

function defaultValue(
    record: DatasetRecord | undefined,
    field: DatasetRecordFieldDefinition,
): string | number {
    const value = record?.values[field.key]?.value;

    if (value === null || value === undefined) {
        return '';
    }

    if (field.dataType === 'datetime' && typeof value === 'string') {
        return value.slice(0, 16);
    }

    return typeof value === 'object'
        ? JSON.stringify(value)
        : (value as string | number);
}

export default function DatasetRecordForm({
    action,
    fields,
    record,
    cancelUrl,
    submitLabel,
}: Props) {
    const { t } = useTranslation();

    return (
        <Form
            {...action}
            options={{ preserveScroll: true }}
            className="space-y-6"
        >
            {({ errors, processing }) => (
                <>
                    {fields.map((field) => {
                        const fieldName = `values[${field.key}]`;
                        const value = defaultValue(record, field);
                        const required = field.config?.required === true;
                        const inputId = `record-${field.key}`;

                        return (
                            <div className="grid gap-2" key={field.key}>
                                <Label htmlFor={inputId}>
                                    {field.label}
                                    {required ? ' *' : ''}
                                </Label>
                                {field.dataType === 'boolean' ? (
                                    <select
                                        id={inputId}
                                        name={fieldName}
                                        defaultValue={
                                            value === ''
                                                ? ''
                                                : value
                                                  ? '1'
                                                  : '0'
                                        }
                                        className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                    >
                                        <option value="">—</option>
                                        <option value="1">
                                            {t('common.yes')}
                                        </option>
                                        <option value="0">
                                            {t('common.no')}
                                        </option>
                                    </select>
                                ) : field.dataType === 'string' &&
                                  (field.key.includes('description') ||
                                      field.key.includes('content')) ? (
                                    <textarea
                                        id={inputId}
                                        name={fieldName}
                                        defaultValue={value}
                                        rows={4}
                                        className="flex min-h-20 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                    />
                                ) : (
                                    <Input
                                        id={inputId}
                                        name={fieldName}
                                        type={
                                            field.dataType === 'integer' ||
                                            field.dataType === 'decimal'
                                                ? 'number'
                                                : field.dataType === 'date'
                                                  ? 'date'
                                                  : field.dataType ===
                                                      'datetime'
                                                    ? 'datetime-local'
                                                    : 'text'
                                        }
                                        step={
                                            field.dataType === 'decimal'
                                                ? 'any'
                                                : undefined
                                        }
                                        defaultValue={value}
                                        required={required}
                                    />
                                )}
                                {field.description ? (
                                    <p className="text-sm text-muted-foreground">
                                        {field.description}
                                    </p>
                                ) : null}
                                <InputError
                                    message={
                                        errors[`values.${field.key}`] ??
                                        errors.values
                                    }
                                />
                            </div>
                        );
                    })}

                    <div className="flex items-center gap-3">
                        <Button type="submit" disabled={processing}>
                            {processing ? t('common.saving') : submitLabel}
                        </Button>
                        <Button variant="ghost" asChild>
                            <Link href={cancelUrl}>{t('common.cancel')}</Link>
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
