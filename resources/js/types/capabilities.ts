export type CapabilityStatus =
    'ready' | 'needs_configuration' | 'unavailable' | 'disabled';

export type CapabilityKind = 'data' | 'live' | 'action';

export type CapabilityDataset = {
    name: string;
    slug: string;
};

export type CapabilityDetails = {
    datasets?: CapabilityDataset[];
    operationName?: string | null;
    dataSourceName?: string | null;
    mode?: 'read' | 'write' | string;
};

export type BotCapability = {
    key: string;
    label: string;
    description: string;
    kind: CapabilityKind;
    status: CapabilityStatus;
    statusMessage: string;
    requiresConfirmation: boolean;
    details: CapabilityDetails;
    configureUrl: string | null;
    configureLabel: string | null;
};

export type BotCapabilityGroup = {
    key: string;
    label: string;
    capabilities: BotCapability[];
};
