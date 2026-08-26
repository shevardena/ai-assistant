import type { Paginated } from './bots';
import type { ConversationChannel } from './channels';
import type { ConversationBlock } from './conversation-blocks';

export type ConversationInboxRange = 'all' | 'today' | '7d' | '30d';

export type ConversationInboxSource = 'customer' | 'preview' | 'all';

export type ConversationInboxChannel = 'all' | ConversationChannel;

export type ConversationInboxHandoff = 'all' | 'needs_attention' | 'human';

export type ConversationInboxStatus =
    'all' | 'open' | 'pending' | 'resolved' | 'closed';

export type ConversationInboxAssignee = 'all' | 'unassigned' | 'me' | string;

export type ConversationInboxFilters = {
    bot: string | null;
    range: ConversationInboxRange;
    source: ConversationInboxSource;
    channel: ConversationInboxChannel;
    handoff: ConversationInboxHandoff;
    status: ConversationInboxStatus;
    assignee: ConversationInboxAssignee;
    tag: string | null;
    search: string | null;
};

export type ConversationBotSummary = {
    name: string;
    slug: string;
};

export type ConversationInboxItem = {
    reference: string;
    channel: ConversationChannel;
    subject: string | null;
    bot: ConversationBotSummary;
    source: 'widget' | 'preview';
    messageCount: number;
    lastMessageAt: string | null;
    preview: string;
    conversationStatus: Exclude<ConversationInboxStatus, 'all'>;
    conversationStatusLabel: string;
    assignee: ConversationAssigneeSummary | null;
    tags: ConversationTagSummary[];
    handoffStatus: 'ai' | 'requested' | 'human';
    handoffLabel: string;
};

export type ConversationChannelOption = {
    key: ConversationChannel;
    name: string;
};

export type ConversationInboxHandoffSummary = {
    needsAttention: number;
    humanActive: number;
};

export type ConversationInboxPageProps = {
    filters: ConversationInboxFilters;
    botOptions: ConversationBotSummary[];
    channelOptions: ConversationChannelOption[];
    assignableUsers: ConversationAssigneeSummary[];
    tagOptions: ConversationTagSummary[];
    conversations: Paginated<ConversationInboxItem>;
    handoffSummary: ConversationInboxHandoffSummary;
    permissions: {
        canReply: boolean;
        canManageHandoff: boolean;
        canManage: boolean;
    };
};

export type ConversationAssigneeSummary = {
    reference: string;
    name: string;
};

export type ConversationTagSummary = {
    reference: string;
    name: string;
    slug: string;
};

export type ConversationActionSummary = {
    actionReference: string;
    name: string;
    status: string;
};

export type ConversationVisitorSummary = {
    label: 'Known customer' | 'Anonymous visitor';
    firstSeenAt: string | null;
    lastSeenAt: string | null;
    conversationCount: number | null;
};

export type ConversationCustomerSummary = {
    channel: ConversationChannel;
    label: string;
    identity: string | null;
    firstSeenAt: string | null;
    lastSeenAt: string | null;
    conversationCount: number;
    email: string | null;
    phone: string | null;
    status: string | null;
    owner: string | null;
    profileUrl: string | null;
};

export type ConversationNoteSummary = {
    reference: string;
    body: string;
    author: string | null;
    createdAt: string | null;
};

export type ConversationRelatedRecord = {
    reference: string;
    label: string;
    status: string;
    url: string;
};

export type ConversationMessage = {
    role: 'user' | 'assistant' | 'system';
    type: string;
    content: string;
    createdAt: string | null;
    blocks: ConversationBlock[];
    source: 'human' | 'system' | null;
    sender: string | null;
};

export type ConversationDetail = {
    reference: string;
    channel: ConversationChannel;
    channelName: string;
    subject: string | null;
    sender: string | null;
    source: 'widget' | 'preview';
    status: string;
    conversationStatus: Exclude<ConversationInboxStatus, 'all'>;
    conversationStatusLabel: string;
    assignee: ConversationAssigneeSummary | null;
    tags: ConversationTagSummary[];
    notes: ConversationNoteSummary[];
    createdAt: string | null;
    lastMessageAt: string | null;
    bot: ConversationBotSummary;
    messages: ConversationMessage[];
    searchesCount: number;
    actions: ConversationActionSummary[];
    visitor: ConversationVisitorSummary;
    customer: ConversationCustomerSummary;
    related: {
        leads: ConversationRelatedRecord[];
        appointments: ConversationRelatedRecord[];
        supportTickets: ConversationRelatedRecord[];
        actions: ConversationRelatedRecord[];
    };
    handoff: {
        status: 'ai' | 'requested' | 'human';
        label: string;
        reason: string | null;
        requestedAt: string | null;
        startedAt: string | null;
        takenOverBy: string | null;
        canTakeOver: boolean;
        canReply: boolean;
        canReturnToAi: boolean;
    };
};

export type ConversationDetailPageProps = {
    conversation: ConversationDetail;
    assignableUsers: ConversationAssigneeSummary[];
    tagOptions: ConversationTagSummary[];
    permissions: {
        canManage: boolean;
    };
};
