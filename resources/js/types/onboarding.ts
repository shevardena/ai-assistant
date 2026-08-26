import type { BotStatus } from './bots';

export type TemplateRequirementType =
    | 'knowledge'
    | 'catalog'
    | 'live_read'
    | 'live_write'
    | 'workflow'
    | 'channel';

export type TemplateRequirementImportance =
    'required' | 'recommended' | 'optional';

export type TemplateDataMode = 'synced' | 'live' | 'hybrid';

export type TemplateSupportStatus =
    'supported' | 'requires_api' | 'future_custom';

export type TemplateSetupActionType =
    | 'none'
    | 'create_dataset'
    | 'connect_data_source'
    | 'configure_live_api'
    | 'configure_write_api'
    | 'configure_channel'
    | 'open_capabilities'
    | 'open_workflows'
    | 'run_bot_test';

export type TemplateSetupAction = {
    type: TemplateSetupActionType;
    url: string | null;
    labelKey: string;
    context: {
        requirement: string;
        capabilities: string[];
    };
};

export type TemplateRequirement = {
    key: string;
    type: TemplateRequirementType;
    importance: TemplateRequirementImportance;
    dataMode: TemplateDataMode | null;
    titleKey: string;
    descriptionKey: string;
    whyKey: string;
    guidanceKey: string | null;
    capabilities: {
        key: string;
        labelKey: string;
        status: string;
    }[];
    recommendedSourceTypes: string[];
    setupAction: TemplateSetupActionType;
    supportStatus: TemplateSupportStatus;
    suggestedFields: string[];
    refreshRecommendation: string | null;
    category:
        | 'data_knowledge'
        | 'live_integrations'
        | 'actions'
        | 'automation'
        | 'channels';
    status: 'not_configured' | 'partially_configured' | 'ready' | 'unavailable';
    statusMessageKey: string;
    statusReasonKey: string;
    dataset: {
        id: number;
        name: string;
        slug: string;
        status: string;
    } | null;
    setup: TemplateSetupAction;
};

export type TemplateWorkflowRecommendation = {
    key: string;
    titleKey: string;
    descriptionKey: string;
};

export type TemplateChannelRecommendation = {
    key: string;
    importance: TemplateRequirementImportance;
    titleKey: string;
    descriptionKey: string;
};

export type TemplateDefinitionRequirement = {
    key: string;
    type: TemplateRequirementType;
    importance: TemplateRequirementImportance;
    dataMode: TemplateDataMode | null;
    titleKey: string;
    descriptionKey: string;
    whyKey: string;
    guidanceKey: string | null;
    capabilities: string[];
    recommendedSourceTypes: string[];
    setupAction: TemplateSetupActionType;
    supportStatus: TemplateSupportStatus;
    suggestedFields: string[];
    refreshRecommendation: string | null;
};

export type BusinessTemplateDefinition = {
    key: string;
    version: number;
    nameKey: string;
    descriptionKey: string;
    bestForKey: string;
    recommendedBotName: string;
    outcomeKeys: string[];
    requirements: TemplateDefinitionRequirement[];
    workflowRecommendations: TemplateWorkflowRecommendation[];
    channelRecommendations: TemplateChannelRecommendation[];
    suggestedTestKeys: string[];
    capabilityCount: number;
    onboardingSteps: {
        key: string;
        labelKey: string;
        descriptionKey: string;
    }[];
};

export type OnboardingIndexProps = {
    templates: BusinessTemplateDefinition[];
    hasBots: boolean;
    scratchUrl: string;
};

export type OnboardingTemplateProps = {
    template: BusinessTemplateDefinition;
    applyUrl: string;
    backUrl: string;
};

export type OnboardingRequirement = TemplateRequirement;

export type OnboardingSetupGroup = {
    key: TemplateRequirement['category'];
    titleKey: string;
    requirements: OnboardingRequirement[];
};

export type OnboardingChecklistStep = {
    key: string;
    labelKey: string;
    descriptionKey: string;
    completed: boolean;
    status: 'complete' | 'incomplete';
    actionUrl: string;
    actionLabelKey: string;
};

export type OnboardingChecklist = {
    progress: {
        completed: number;
        total: number;
        percentage: number;
        requiredReady: boolean;
        launchReady: boolean;
        required: number;
        recommended: number;
        optional: number;
    };
    requirements: OnboardingRequirement[];
    groups: OnboardingSetupGroup[];
    steps: OnboardingChecklistStep[];
    workflows: (TemplateWorkflowRecommendation & {
        status: string;
        setup: TemplateSetupAction;
    })[];
    channels: (TemplateChannelRecommendation & {
        status: string;
        setup: TemplateSetupAction;
    })[];
    suggestedTests: string[];
    legacyProgress: {
        completed: number;
        total: number;
        percentage: number;
    };
};

export type BotSetupProps = {
    bot: {
        id: number;
        name: string;
        slug: string;
        status: BotStatus;
        businessTemplate: string | null;
    };
    template: BusinessTemplateDefinition;
    checklist: OnboardingChecklist;
};
