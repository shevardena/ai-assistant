import type { Paginated } from './bots';

export type KnowledgeGapStatus = 'open' | 'resolved' | 'ignored';

export type KnowledgeGapReason = 'no_knowledge_match' | 'no_results';

export type KnowledgeGapRange = 'today' | '7d' | '30d' | '90d';

export type KnowledgeGapFilters = {
    bot: string | null;
    range: KnowledgeGapRange;
    status: KnowledgeGapStatus | 'all';
    reason: KnowledgeGapReason | null;
    search: string | null;
};

export type KnowledgeGapBot = {
    name: string;
    slug: string;
};

export type KnowledgeGapOccurrence = {
    question: string;
    askedAt: string | null;
    conversationReference: string;
};

export type KnowledgeGapGroup = {
    groupReference: string;
    question: string;
    reason: KnowledgeGapReason;
    status: KnowledgeGapStatus;
    occurrenceCount: number;
    conversationCount: number;
    lastAskedAt: string | null;
    bot: KnowledgeGapBot;
    occurrences: KnowledgeGapOccurrence[];
    occurrencesCapped: boolean;
};

export type KnowledgeGapSummary = {
    openGaps: number;
    affectedConversations: number;
    repeatedQuestions: number;
};

export type KnowledgeGapPageProps = {
    filters: KnowledgeGapFilters;
    botOptions: KnowledgeGapBot[];
    summary: KnowledgeGapSummary;
    gaps: Paginated<KnowledgeGapGroup>;
};
