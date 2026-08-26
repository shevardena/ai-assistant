import type { Bot, Paginated } from './bots';

export type BotTestExpectationType =
    | 'tool_called'
    | 'tool_not_called'
    | 'response_contains'
    | 'response_not_contains'
    | 'block_present'
    | 'block_absent'
    | 'action_status';

export type BotTestExpectation = {
    type: BotTestExpectationType;
    value: string;
};

export type BotTestRunStatus = 'passed' | 'failed' | 'error';

export type BotTestExpectationResult = {
    type: string;
    expected: string;
    passed: boolean;
    actual: string | string[];
};

export type BotTestRunSummary = {
    tools_called?: string[];
    blocks_returned?: string[];
    action_proposals?: string[];
    final_text?: string;
    runtime_status?: string;
    message?: string;
    expectation_results?: BotTestExpectationResult[];
};

export type BotTestRun = {
    publicId: string;
    status: BotTestRunStatus;
    startedAt: string | null;
    finishedAt: string | null;
    durationMs: number | null;
    responseText: string | null;
    resultSummary: BotTestRunSummary;
};

export type BotTestScenario = {
    publicId: string;
    name: string;
    inputMessage: string;
    isEnabled: boolean;
    expectations: BotTestExpectation[];
    runCount: number;
    latestRun: BotTestRun | null;
    createdAt: string | null;
    updatedAt: string | null;
};

export type BotTestSummary = {
    total: number;
    enabled: number;
    passing: number;
    failing: number;
    not_run: number;
};

export type BotTestBlockOption = {
    value: string;
    label: string;
};

export type BotTestPageProps = {
    bot: Pick<Bot, 'id' | 'name' | 'slug'>;
    scenarios: Paginated<BotTestScenario>;
    summary: BotTestSummary;
    tools: string[];
    blocks: BotTestBlockOption[];
};

export type BotTestFormPageProps = {
    bot: Pick<Bot, 'id' | 'name' | 'slug'>;
    tools: string[];
    blocks: BotTestBlockOption[];
};

export type BotTestDetailPageProps = BotTestFormPageProps & {
    scenario: BotTestScenario;
    runs: BotTestRun[];
};
