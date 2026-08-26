import { Form, Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/workflows';
import type {
    Workflow,
    WorkflowAction,
    WorkflowBuilderDefinition,
    WorkflowCondition,
    WorkflowMetadata,
    WorkflowTriggerType,
} from '@/types';
import type { RouteFormDefinition } from '@/wayfinder';

type Props = {
    action: RouteFormDefinition<'post'>;
    currentTeamSlug: string;
    metadata: WorkflowMetadata;
    workflow?: Workflow | null;
    submitLabel: string;
};

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

function initialConditions(
    workflow: Workflow | null | undefined,
): WorkflowCondition[] {
    return workflow?.conditions ?? [];
}

function initialActions(
    workflow: Workflow | null | undefined,
    definition: WorkflowBuilderDefinition,
): WorkflowAction[] {
    return (
        workflow?.actions ??
        (definition.actions[0]
            ? [{ type: definition.actions[0].value, config: {} }]
            : [])
    );
}

export default function WorkflowForm({
    action,
    currentTeamSlug,
    metadata,
    workflow,
    submitLabel,
}: Props) {
    const [trigger, setTrigger] = useState<WorkflowTriggerType>(
        workflow?.triggerType ?? 'lead_captured',
    );
    const definition = metadata.definitions[trigger];
    const [conditions, setConditions] = useState<WorkflowCondition[]>(
        initialConditions(workflow),
    );
    const [actions, setActions] = useState<WorkflowAction[]>(
        initialActions(workflow, definition),
    );
    const conditionMetadata = useMemo(
        () =>
            Object.fromEntries(
                definition.conditions.map((item) => [item.value, item]),
            ),
        [definition],
    );

    function changeTrigger(value: WorkflowTriggerType) {
        setTrigger(value);
        const next = metadata.definitions[value];
        setConditions([]);
        setActions(
            next.actions[0]
                ? [{ type: next.actions[0].value, config: {} }]
                : [],
        );
    }

    function addCondition() {
        const field = definition.conditions[0];

        if (field) {
            setConditions((current) => [
                ...current,
                {
                    type: field.value,
                    operator: 'equals',
                    value: String(field.options[0]?.value ?? ''),
                },
            ]);
        }
    }

    function addAction() {
        const field = definition.actions[0];

        if (field) {
            setActions((current) => [
                ...current,
                { type: field.value, config: {} },
            ]);
        }
    }

    return (
        <Form
            {...action}
            options={{ preserveScroll: true }}
            className="space-y-8"
        >
            {({ errors, processing }) => (
                <>
                    <div className="grid gap-5 rounded-xl border p-5">
                        <div className="grid gap-2">
                            <Label htmlFor="name">Workflow name</Label>
                            <Input
                                id="name"
                                name="name"
                                defaultValue={workflow?.name ?? ''}
                                required
                                autoFocus
                                placeholder="Lead follow-up"
                            />
                            <InputError message={errors.name} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="description">Description</Label>
                            <textarea
                                id="description"
                                name="description"
                                defaultValue={workflow?.description ?? ''}
                                rows={3}
                                className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm outline-none focus-visible:border-ring"
                                placeholder="What should this automation do?"
                            />
                            <InputError message={errors.description} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="trigger_type">When</Label>
                            <select
                                id="trigger_type"
                                name="trigger_type"
                                value={trigger}
                                onChange={(event) =>
                                    changeTrigger(
                                        event.target
                                            .value as WorkflowTriggerType,
                                    )
                                }
                                className={selectClass}
                            >
                                {metadata.triggers.map((option) => (
                                    <option
                                        key={String(option.value)}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                            <p className="text-xs text-muted-foreground">
                                Only trusted server-side events can start a
                                workflow.
                            </p>
                            <InputError message={errors.trigger_type} />
                        </div>
                    </div>

                    <section className="grid gap-4 rounded-xl border p-5">
                        <div className="flex items-center justify-between gap-3">
                            <div>
                                <h2 className="font-semibold">If</h2>
                                <p className="text-sm text-muted-foreground">
                                    All conditions must match.
                                </p>
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={addCondition}
                            >
                                Add condition
                            </Button>
                        </div>
                        {conditions.map((condition, position) => {
                            const field =
                                conditionMetadata[condition.type] ??
                                definition.conditions[0];

                            return (
                                <div
                                    key={position}
                                    className="grid gap-3 rounded-lg bg-muted/30 p-3 md:grid-cols-[1fr_1fr_1fr_auto] md:items-end"
                                >
                                    <div className="grid gap-1.5">
                                        <Label>Field</Label>
                                        <select
                                            name={`conditions[${position}][type]`}
                                            value={condition.type}
                                            onChange={(event) =>
                                                setConditions((current) =>
                                                    current.map(
                                                        (item, index) =>
                                                            index === position
                                                                ? {
                                                                      ...item,
                                                                      type: event
                                                                          .target
                                                                          .value,
                                                                      value: String(
                                                                          conditionMetadata[
                                                                              event
                                                                                  .target
                                                                                  .value
                                                                          ]
                                                                              ?.options[0]
                                                                              ?.value ??
                                                                              '',
                                                                      ),
                                                                  }
                                                                : item,
                                                    ),
                                                )
                                            }
                                            className={selectClass}
                                        >
                                            {definition.conditions.map(
                                                (option) => (
                                                    <option
                                                        key={option.value}
                                                        value={option.value}
                                                    >
                                                        {option.label}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label>Operator</Label>
                                        <select
                                            name={`conditions[${position}][operator]`}
                                            value={condition.operator}
                                            onChange={(event) =>
                                                setConditions((current) =>
                                                    current.map(
                                                        (item, index) =>
                                                            index === position
                                                                ? {
                                                                      ...item,
                                                                      operator:
                                                                          event
                                                                              .target
                                                                              .value as WorkflowCondition['operator'],
                                                                  }
                                                                : item,
                                                    ),
                                                )
                                            }
                                            className={selectClass}
                                        >
                                            {definition.operators.map(
                                                (option) => (
                                                    <option
                                                        key={String(
                                                            option.value,
                                                        )}
                                                        value={option.value}
                                                    >
                                                        {option.label}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label>Value</Label>
                                        {field?.options.length ? (
                                            <select
                                                name={`conditions[${position}][value]`}
                                                value={String(condition.value)}
                                                onChange={(event) =>
                                                    setConditions((current) =>
                                                        current.map(
                                                            (item, index) =>
                                                                index ===
                                                                position
                                                                    ? {
                                                                          ...item,
                                                                          value: event
                                                                              .target
                                                                              .value,
                                                                      }
                                                                    : item,
                                                        ),
                                                    )
                                                }
                                                className={selectClass}
                                            >
                                                {field.options.map((option) => (
                                                    <option
                                                        key={String(
                                                            option.value,
                                                        )}
                                                        value={option.value}
                                                    >
                                                        {option.label}
                                                    </option>
                                                ))}
                                            </select>
                                        ) : (
                                            <Input
                                                name={`conditions[${position}][value]`}
                                                value={String(condition.value)}
                                                onChange={(event) =>
                                                    setConditions((current) =>
                                                        current.map(
                                                            (item, index) =>
                                                                index ===
                                                                position
                                                                    ? {
                                                                          ...item,
                                                                          value: event
                                                                              .target
                                                                              .value,
                                                                      }
                                                                    : item,
                                                        ),
                                                    )
                                                }
                                            />
                                        )}
                                    </div>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        onClick={() =>
                                            setConditions((current) =>
                                                current.filter(
                                                    (_, index) =>
                                                        index !== position,
                                                ),
                                            )
                                        }
                                    >
                                        Remove
                                    </Button>
                                    <InputError
                                        message={
                                            errors[
                                                `conditions.${position}.value`
                                            ]
                                        }
                                    />
                                </div>
                            );
                        })}
                    </section>

                    <section className="grid gap-4 rounded-xl border p-5">
                        <div className="flex items-center justify-between gap-3">
                            <div>
                                <h2 className="font-semibold">Then</h2>
                                <p className="text-sm text-muted-foreground">
                                    Actions run in order and stop on the first
                                    failure.
                                </p>
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={addAction}
                            >
                                Add action
                            </Button>
                        </div>
                        {actions.map((actionItem, position) => {
                            const actionMeta =
                                definition.actions.find(
                                    (item) => item.value === actionItem.type,
                                ) ?? definition.actions[0];
                            const config = actionItem.config;

                            return (
                                <div
                                    key={position}
                                    className="grid gap-3 rounded-lg bg-muted/30 p-3"
                                >
                                    <div className="flex items-end gap-3">
                                        <div className="grid flex-1 gap-1.5">
                                            <Label>Action {position + 1}</Label>
                                            <select
                                                name={`actions[${position}][type]`}
                                                value={actionItem.type}
                                                onChange={(event) =>
                                                    setActions((current) =>
                                                        current.map(
                                                            (item, index) =>
                                                                index ===
                                                                position
                                                                    ? {
                                                                          type: event
                                                                              .target
                                                                              .value,
                                                                          config: {},
                                                                      }
                                                                    : item,
                                                        ),
                                                    )
                                                }
                                                className={selectClass}
                                            >
                                                {definition.actions.map(
                                                    (option) => (
                                                        <option
                                                            key={option.value}
                                                            value={option.value}
                                                        >
                                                            {option.label}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                        </div>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            onClick={() =>
                                                setActions((current) =>
                                                    current.filter(
                                                        (_, index) =>
                                                            index !== position,
                                                    ),
                                                )
                                            }
                                        >
                                            Remove
                                        </Button>
                                    </div>
                                    {actionMeta?.value ===
                                    'send_in_app_notification' ? (
                                        <div className="grid gap-3 md:grid-cols-2">
                                            <div className="grid gap-1.5">
                                                <Label>Recipients</Label>
                                                <select
                                                    name={`actions[${position}][config][permission]`}
                                                    value={
                                                        config.permission ?? ''
                                                    }
                                                    onChange={(event) =>
                                                        setActions((current) =>
                                                            current.map(
                                                                (
                                                                    item,
                                                                    index,
                                                                ) =>
                                                                    index ===
                                                                    position
                                                                        ? {
                                                                              ...item,
                                                                              config: {
                                                                                  ...item.config,
                                                                                  permission:
                                                                                      event
                                                                                          .target
                                                                                          .value,
                                                                              },
                                                                          }
                                                                        : item,
                                                            ),
                                                        )
                                                    }
                                                    className={selectClass}
                                                >
                                                    <option value="">
                                                        Choose a Team category
                                                    </option>
                                                    {actionMeta.permissions.map(
                                                        (option) => (
                                                            <option
                                                                key={String(
                                                                    option.value,
                                                                )}
                                                                value={
                                                                    option.value
                                                                }
                                                            >
                                                                {option.label}
                                                            </option>
                                                        ),
                                                    )}
                                                </select>
                                            </div>
                                            <div className="grid gap-1.5">
                                                <Label>Title</Label>
                                                <Input
                                                    name={`actions[${position}][config][title]`}
                                                    value={config.title ?? ''}
                                                    onChange={(event) =>
                                                        setActions((current) =>
                                                            current.map(
                                                                (
                                                                    item,
                                                                    index,
                                                                ) =>
                                                                    index ===
                                                                    position
                                                                        ? {
                                                                              ...item,
                                                                              config: {
                                                                                  ...item.config,
                                                                                  title: event
                                                                                      .target
                                                                                      .value,
                                                                              },
                                                                          }
                                                                        : item,
                                                            ),
                                                        )
                                                    }
                                                />
                                            </div>
                                            <div className="grid gap-1.5 md:col-span-2">
                                                <Label>Message</Label>
                                                <Input
                                                    name={`actions[${position}][config][message]`}
                                                    value={config.message ?? ''}
                                                    onChange={(event) =>
                                                        setActions((current) =>
                                                            current.map(
                                                                (
                                                                    item,
                                                                    index,
                                                                ) =>
                                                                    index ===
                                                                    position
                                                                        ? {
                                                                              ...item,
                                                                              config: {
                                                                                  ...item.config,
                                                                                  message:
                                                                                      event
                                                                                          .target
                                                                                          .value,
                                                                              },
                                                                          }
                                                                        : item,
                                                            ),
                                                        )
                                                    }
                                                />
                                            </div>
                                        </div>
                                    ) : (
                                        <div className="grid gap-1.5">
                                            <Label>
                                                {actionMeta?.value ===
                                                'request_human_handoff'
                                                    ? 'Reason'
                                                    : 'Status'}
                                            </Label>
                                            <select
                                                name={`actions[${position}][config][${actionMeta?.value === 'request_human_handoff' ? 'reason' : 'status'}]`}
                                                value={
                                                    config.status ??
                                                    config.reason ??
                                                    ''
                                                }
                                                onChange={(event) =>
                                                    setActions((current) =>
                                                        current.map(
                                                            (item, index) =>
                                                                index ===
                                                                position
                                                                    ? {
                                                                          ...item,
                                                                          config:
                                                                              actionMeta?.value ===
                                                                              'request_human_handoff'
                                                                                  ? {
                                                                                        reason: event
                                                                                            .target
                                                                                            .value,
                                                                                    }
                                                                                  : {
                                                                                        status: event
                                                                                            .target
                                                                                            .value,
                                                                                    },
                                                                      }
                                                                    : item,
                                                        ),
                                                    )
                                                }
                                                className={selectClass}
                                            >
                                                <option value="">
                                                    Choose a value
                                                </option>
                                                {actionMeta?.options.map(
                                                    (option) => (
                                                        <option
                                                            key={String(
                                                                option.value,
                                                            )}
                                                            value={option.value}
                                                        >
                                                            {option.label}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                        </div>
                                    )}
                                    <InputError
                                        message={
                                            errors[`actions.${position}.config`]
                                        }
                                    />
                                </div>
                            );
                        })}
                        <InputError message={errors.actions} />
                    </section>

                    {workflow ? (
                        <input
                            type="hidden"
                            name="status"
                            value={workflow.status}
                        />
                    ) : null}
                    <div className="flex items-center gap-3">
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Saving...' : submitLabel}
                        </Button>
                        <Button variant="ghost" asChild>
                            <Link href={index(currentTeamSlug).url}>
                                Cancel
                            </Link>
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
