export type WorkflowStatus = 'draft' | 'active' | 'disabled';
export type WorkflowTriggerType =
    | 'lead_captured'
    | 'appointment_booked'
    | 'support_ticket_created'
    | 'human_handoff_requested';
export type WorkflowConditionOperator = 'equals' | 'not_equals';
export type WorkflowConditionType = string;
export type WorkflowActionType = string;

export type WorkflowOption = { value: string | number; label: string };
export type WorkflowConditionMetadata = {
    value: string;
    label: string;
    options: WorkflowOption[];
};
export type WorkflowActionMetadata = {
    value: string;
    label: string;
    permissions: WorkflowOption[];
    options: WorkflowOption[];
};
export type WorkflowBuilderDefinition = {
    conditions: WorkflowConditionMetadata[];
    operators: WorkflowOption[];
    actions: WorkflowActionMetadata[];
};
export type WorkflowMetadata = {
    triggers: WorkflowOption[];
    conditions: WorkflowConditionMetadata[];
    operators: WorkflowOption[];
    actions: WorkflowActionMetadata[];
    definitions: Record<WorkflowTriggerType, WorkflowBuilderDefinition>;
};
export type WorkflowCondition = {
    type: string;
    operator: WorkflowConditionOperator;
    value: string | number;
};
export type WorkflowAction = {
    type: string;
    config: Record<string, string>;
};
export type Workflow = {
    publicId: string;
    name: string;
    description: string | null;
    status: WorkflowStatus;
    triggerType: WorkflowTriggerType;
    isEnabled: boolean;
    conditionCount: number;
    actionCount: number;
    conditions: WorkflowCondition[];
    actions: WorkflowAction[];
    lastRun: { status: string; createdAt: string | null } | null;
    createdAt: string | null;
    updatedAt: string | null;
};
export type WorkflowRun = {
    publicId: string;
    status: string;
    triggerType: string;
    triggerReference: string;
    errorCode: string | null;
    startedAt: string | null;
    finishedAt: string | null;
    actions: {
        type: string;
        status: string;
        position: number;
        safeSummary: string | null;
        errorCode: string | null;
    }[];
};
