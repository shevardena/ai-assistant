import { Form, Link } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type {
    BotTestBlockOption,
    BotTestExpectation,
    BotTestExpectationType,
} from '@/types';
import type { RouteFormDefinition } from '@/wayfinder';

type Props = {
    action: RouteFormDefinition<'post'>;
    cancelHref: string;
    submitLabel: string;
    tools: string[];
    blocks: BotTestBlockOption[];
    scenario?: {
        name: string;
        inputMessage: string;
        isEnabled: boolean;
        expectations: BotTestExpectation[];
    };
};

const types: { value: BotTestExpectationType; label: string }[] = [
    { value: 'tool_called', label: 'Tool called' },
    { value: 'tool_not_called', label: 'Tool not called' },
    { value: 'response_contains', label: 'Response contains' },
    { value: 'response_not_contains', label: 'Response does not contain' },
    { value: 'block_present', label: 'Block present' },
    { value: 'block_absent', label: 'Block absent' },
    { value: 'action_status', label: 'Action status' },
];

function valueOptions(
    type: BotTestExpectationType,
    tools: string[],
    blocks: BotTestBlockOption[],
): { value: string; label: string }[] | null {
    if (type === 'tool_called' || type === 'tool_not_called') {
        return tools.map((tool) => ({ value: tool, label: tool }));
    }

    if (type === 'block_present' || type === 'block_absent') {
        return blocks;
    }

    if (type === 'action_status') {
        return [
            { value: 'proposed', label: 'Action proposed' },
            { value: 'not_proposed', label: 'No action proposed' },
        ];
    }

    return null;
}

export default function BotTestForm({
    action,
    cancelHref,
    submitLabel,
    tools,
    blocks,
    scenario,
}: Props) {
    const [expectations, setExpectations] = useState<BotTestExpectation[]>(
        scenario?.expectations ?? [],
    );

    function addExpectation() {
        if (expectations.length < 12) {
            setExpectations((current) => [
                ...current,
                { type: 'response_contains', value: '' },
            ]);
        }
    }

    function updateExpectation(
        index: number,
        field: keyof BotTestExpectation,
        value: string,
    ) {
        setExpectations((current) =>
            current.map((expectation, expectationIndex) =>
                expectationIndex === index
                    ? field === 'type'
                        ? {
                              type: value as BotTestExpectationType,
                              value: '',
                          }
                        : { ...expectation, [field]: value }
                    : expectation,
            ),
        );
    }

    return (
        <Form
            {...action}
            options={{ preserveScroll: true }}
            className="space-y-6"
        >
            {({ errors, processing }) => (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="name">Scenario name</Label>
                        <Input
                            id="name"
                            name="name"
                            defaultValue={scenario?.name ?? ''}
                            placeholder="Blue laptop availability"
                            required
                            autoFocus
                        />
                        <InputError message={errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="input_message">Test message</Label>
                        <textarea
                            id="input_message"
                            name="input_message"
                            defaultValue={scenario?.inputMessage ?? ''}
                            rows={4}
                            required
                            placeholder="Do you have the blue laptop in stock?"
                            className="min-h-24 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        />
                        <InputError message={errors.input_message} />
                    </div>

                    <label className="flex items-center gap-2 text-sm">
                        <input type="hidden" name="is_enabled" value="0" />
                        <input
                            type="checkbox"
                            name="is_enabled"
                            value="1"
                            defaultChecked={scenario?.isEnabled ?? true}
                        />
                        Run this scenario in future regression checks.
                    </label>

                    <div className="space-y-4">
                        <div className="flex items-center justify-between gap-4">
                            <div>
                                <Label>Expectations</Label>
                                <p className="text-sm text-muted-foreground">
                                    Add up to 12 deterministic checks for this
                                    turn.
                                </p>
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={addExpectation}
                                disabled={expectations.length >= 12}
                            >
                                <Plus />
                                Add expectation
                            </Button>
                        </div>

                        {expectations.map((expectation, index) => {
                            const options = valueOptions(
                                expectation.type,
                                tools,
                                blocks,
                            );

                            return (
                                <div
                                    key={`${index}-${expectation.type}`}
                                    className="grid gap-3 rounded-lg border p-4 md:grid-cols-[1fr_1fr_auto]"
                                >
                                    <div className="grid gap-2">
                                        <Label
                                            htmlFor={`expectation-type-${index}`}
                                        >
                                            Check
                                        </Label>
                                        <select
                                            id={`expectation-type-${index}`}
                                            name={`expectations[${index}][type]`}
                                            value={expectation.type}
                                            onChange={(event) =>
                                                updateExpectation(
                                                    index,
                                                    'type',
                                                    event.target.value,
                                                )
                                            }
                                            className="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                                        >
                                            {types.map((type) => (
                                                <option
                                                    key={type.value}
                                                    value={type.value}
                                                >
                                                    {type.label}
                                                </option>
                                            ))}
                                        </select>
                                        <InputError
                                            message={
                                                errors[
                                                    `expectations.${index}.type`
                                                ]
                                            }
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label
                                            htmlFor={`expectation-value-${index}`}
                                        >
                                            Expected value
                                        </Label>
                                        {options ? (
                                            <select
                                                id={`expectation-value-${index}`}
                                                name={`expectations[${index}][value]`}
                                                value={expectation.value}
                                                onChange={(event) =>
                                                    updateExpectation(
                                                        index,
                                                        'value',
                                                        event.target.value,
                                                    )
                                                }
                                                className="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                                            >
                                                <option value="">
                                                    Choose a value
                                                </option>
                                                {options.map((option) => (
                                                    <option
                                                        key={option.value}
                                                        value={option.value}
                                                    >
                                                        {option.label}
                                                    </option>
                                                ))}
                                            </select>
                                        ) : (
                                            <Input
                                                id={`expectation-value-${index}`}
                                                name={`expectations[${index}][value]`}
                                                value={expectation.value}
                                                onChange={(event) =>
                                                    updateExpectation(
                                                        index,
                                                        'value',
                                                        event.target.value,
                                                    )
                                                }
                                                maxLength={500}
                                                placeholder="available"
                                            />
                                        )}
                                        <InputError
                                            message={
                                                errors[
                                                    `expectations.${index}.value`
                                                ]
                                            }
                                        />
                                    </div>

                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="self-end"
                                        aria-label="Remove expectation"
                                        onClick={() =>
                                            setExpectations((current) =>
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
                            );
                        })}
                        <InputError message={errors.expectations} />
                    </div>

                    <div className="flex items-center gap-4">
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Saving...' : submitLabel}
                        </Button>
                        <Button variant="ghost" asChild>
                            <Link href={cancelHref}>Cancel</Link>
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
