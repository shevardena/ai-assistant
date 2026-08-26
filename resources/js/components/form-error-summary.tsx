import AlertError from '@/components/alert-error';

type FormErrors = Record<string, string | string[] | undefined>;

type Props = {
    errors?: FormErrors;
    className?: string;
    title?: string;
};

export default function FormErrorSummary({
    errors,
    className,
    title = 'Please fix the following errors.',
}: Props) {
    const messages = Object.values(errors ?? {}).flatMap((value) =>
        Array.isArray(value) ? value : value ? [value] : [],
    );
    const uniqueMessages = Array.from(new Set(messages));

    if (uniqueMessages.length === 0) {
        return null;
    }

    return (
        <AlertError
            errors={uniqueMessages}
            title={title}
            className={className}
        />
    );
}
