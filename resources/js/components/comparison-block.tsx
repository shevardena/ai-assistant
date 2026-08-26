import type { ConfirmationBlockAppearance } from '@/components/confirmation-block';
import type { ComparisonBlock as ComparisonBlockData } from '@/types';

export function ComparisonBlock({
    block,
    appearance,
}: {
    block: ComparisonBlockData;
    appearance?: ConfirmationBlockAppearance;
}) {
    if (block.data.items.length < 2 || block.data.fields.length === 0) {
        return null;
    }

    return (
        <section
            className="mt-3 max-w-full rounded-lg border p-3 text-sm"
            style={{
                backgroundColor: safeColor(
                    appearance?.backgroundColor,
                    '#ffffff',
                ),
                color: safeColor(appearance?.textColor, '#171717'),
            }}
            aria-label="Product comparison"
        >
            <h3 className="mb-3 font-medium">Compare</h3>
            <div className="max-w-full overflow-x-auto overscroll-x-contain">
                <table className="min-w-[34rem] border-collapse text-left">
                    <caption className="sr-only">
                        Comparison of{' '}
                        {block.data.items.map((item) => item.label).join(', ')}
                    </caption>
                    <thead>
                        <tr>
                            <th
                                scope="col"
                                className="sticky left-0 min-w-32 border-b px-3 py-2 font-medium"
                                style={{
                                    backgroundColor: safeColor(
                                        appearance?.backgroundColor,
                                        '#ffffff',
                                    ),
                                }}
                            >
                                Attribute
                            </th>
                            {block.data.items.map((item) => (
                                <th
                                    key={item.product_reference}
                                    scope="col"
                                    className="min-w-40 border-b px-3 py-2 font-medium"
                                >
                                    {item.label}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {block.data.fields.map((field) => (
                            <tr key={field.key}>
                                <th
                                    scope="row"
                                    className="sticky left-0 border-b px-3 py-2 font-medium"
                                    style={{
                                        backgroundColor: safeColor(
                                            appearance?.backgroundColor,
                                            '#ffffff',
                                        ),
                                    }}
                                >
                                    {field.label}
                                </th>
                                {block.data.items.map((item, index) => (
                                    <td
                                        key={`${field.key}-${item.product_reference}`}
                                        className="border-b px-3 py-2 align-top"
                                    >
                                        {formatValue(field.values[index])}
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </section>
    );
}

function formatValue(
    value: string | number | boolean | null | undefined,
): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (typeof value === 'boolean') {
        return value ? 'Yes' : 'No';
    }

    return String(value);
}

function safeColor(value: string | undefined, fallback: string): string {
    return value && /^#[0-9a-f]{6}$/i.test(value) ? value : fallback;
}
