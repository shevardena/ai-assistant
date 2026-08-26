import { useState } from 'react';
import type { ChangeEvent, FormEvent } from 'react';
import type { ConfirmationBlockAppearance } from '@/components/confirmation-block';
import type {
    ConversationBlock,
    FormBlock as FormBlockData,
    FormField,
} from '@/types';

export type FormSubmissionResult = {
    block: FormBlockData;
    user_message?: {
        role: 'user';
        content: string;
        blocks?: ConversationBlock[];
    };
    message?: {
        role: 'assistant';
        content: string;
        blocks?: ConversationBlock[];
        cards?: unknown[];
    };
};

export type FormBlockAction = (
    formReference: string,
    values: Record<string, string>,
) => Promise<FormSubmissionResult>;

export class FormSubmissionError extends Error {
    constructor(
        message: string,
        public readonly fieldErrors: Record<string, string[]> = {},
    ) {
        super(message);
        this.name = 'FormSubmissionError';
    }
}

export function FormBlock({
    block,
    onSubmit,
    appearance,
}: {
    block: FormBlockData;
    onSubmit?: FormBlockAction;
    appearance?: ConfirmationBlockAppearance;
}) {
    const [currentBlock, setCurrentBlock] = useState(block);
    const [values, setValues] = useState<Record<string, string>>(() =>
        Object.fromEntries(block.data.fields.map((field) => [field.name, ''])),
    );
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [generalError, setGeneralError] = useState<string | null>(null);

    const pending = currentBlock.data.status === 'pending';
    const interactive = pending && onSubmit !== undefined && !processing;

    function updateValue(name: string, value: string) {
        setValues((current) => ({ ...current, [name]: value }));
        setErrors((current) => {
            const next = { ...current };
            delete next[name];

            return next;
        });
    }

    async function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        if (!interactive || onSubmit === undefined) {
            return;
        }

        const clientErrors = validateFields(currentBlock.data.fields, values);

        if (Object.keys(clientErrors).length > 0) {
            setErrors(clientErrors);

            return;
        }

        setProcessing(true);
        setErrors({});
        setGeneralError(null);

        try {
            const result = await onSubmit(
                currentBlock.data.form_reference,
                values,
            );
            setCurrentBlock(result.block);
        } catch (error) {
            if (error instanceof FormSubmissionError) {
                setErrors(error.fieldErrors);
                setGeneralError(error.message);
            } else {
                setGeneralError(
                    'Could not submit these details. Please try again.',
                );
            }
        } finally {
            setProcessing(false);
        }
    }

    return (
        <section
            className="mt-3 grid gap-3 rounded-lg border p-3 text-sm"
            style={{
                backgroundColor: safeColor(
                    appearance?.backgroundColor,
                    '#ffffff',
                ),
                color: safeColor(appearance?.textColor, '#171717'),
            }}
            aria-label="Information form"
            aria-live="polite"
        >
            {currentBlock.data.title ? (
                <h3 className="font-medium">{currentBlock.data.title}</h3>
            ) : null}
            {currentBlock.data.description ? (
                <p className="text-xs opacity-70">
                    {currentBlock.data.description}
                </p>
            ) : null}
            <form className="grid gap-3" onSubmit={submit}>
                {currentBlock.data.fields.map((field) => (
                    <FormFieldInput
                        key={field.name}
                        field={field}
                        value={values[field.name] ?? ''}
                        errors={errors[field.name] ?? []}
                        disabled={!interactive}
                        onChange={(value) => updateValue(field.name, value)}
                    />
                ))}
                {generalError ? (
                    <p className="text-xs text-red-600" role="alert">
                        {generalError}
                    </p>
                ) : null}
                {pending ? (
                    <button
                        type="submit"
                        className="rounded-md px-3 py-2 text-xs font-medium disabled:cursor-not-allowed disabled:opacity-50"
                        style={{
                            backgroundColor: safeColor(
                                appearance?.buttonColor,
                                '#171717',
                            ),
                            color: safeColor(
                                appearance?.buttonTextColor,
                                '#ffffff',
                            ),
                        }}
                        disabled={!interactive}
                    >
                        {processing
                            ? 'Processing…'
                            : currentBlock.data.submit_label}
                    </button>
                ) : (
                    <p className="text-xs opacity-70">
                        {currentBlock.data.status === 'submitted'
                            ? 'Submitted'
                            : 'Cancelled'}
                    </p>
                )}
            </form>
        </section>
    );
}

function FormFieldInput({
    field,
    value,
    errors,
    disabled,
    onChange,
}: {
    field: FormField;
    value: string;
    errors: string[];
    disabled: boolean;
    onChange: (value: string) => void;
}) {
    const commonProps = {
        id: `form-${field.name}`,
        name: field.name,
        value,
        required: field.required,
        disabled,
        placeholder: field.placeholder,
        onChange: (
            event: ChangeEvent<
                HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement
            >,
        ) => onChange(event.target.value),
        className:
            'w-full rounded-md border bg-transparent px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-60',
        'aria-invalid': errors.length > 0,
        'aria-describedby':
            errors.length > 0 ? `form-${field.name}-error` : undefined,
    };

    return (
        <label className="grid gap-1" htmlFor={commonProps.id}>
            <span className="text-xs font-medium">
                {field.label}
                {field.required ? ' *' : ''}
            </span>
            {field.type === 'textarea' ? (
                <textarea {...commonProps} rows={3} />
            ) : field.type === 'select' ? (
                <select {...commonProps}>
                    <option value="">Select an option</option>
                    {(field.options ?? []).map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </select>
            ) : (
                <input {...commonProps} type={field.type} />
            )}
            {field.help_text ? (
                <span className="text-xs opacity-70">{field.help_text}</span>
            ) : null}
            {errors.length > 0 ? (
                <span
                    id={`form-${field.name}-error`}
                    className="text-xs text-red-600"
                    role="alert"
                >
                    {errors[0]}
                </span>
            ) : null}
        </label>
    );
}

function validateFields(
    fields: FormField[],
    values: Record<string, string>,
): Record<string, string[]> {
    const errors: Record<string, string[]> = {};

    fields.forEach((field) => {
        const value = (values[field.name] ?? '').trim();

        if (field.required && value === '') {
            errors[field.name] = ['This field is required.'];
        } else if (
            value !== '' &&
            field.type === 'email' &&
            !/^\S+@\S+\.\S+$/.test(value)
        ) {
            errors[field.name] = ['Enter a valid email address.'];
        } else if (
            value !== '' &&
            field.type === 'number' &&
            !Number.isFinite(Number(value))
        ) {
            errors[field.name] = ['Enter a valid number.'];
        }
    });

    return errors;
}

function safeColor(value: string | undefined, fallback: string): string {
    return value && /^#[0-9a-f]{6}$/i.test(value) ? value : fallback;
}
