<?php

namespace App\Services\Ai;

use App\Enums\RuntimeMode;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Ai\Contracts\AiClient;
use App\Services\Ai\Tools\Contracts\BotTool;
use App\Services\Ai\Tools\ToolExecutionContext;
use App\Services\Ai\Tools\ToolResult;
use App\Services\Conversations\Blocks\ConversationBlockNormalizer;
use App\Services\Conversations\ConversationCycleLogger;
use App\Services\Conversations\ConversationHandoffService;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

class AiSearchOrchestrator
{
    public function __construct(
        private readonly AiClient $client,
        private readonly BotRuntimeContextBuilder $contextBuilder,
        private readonly BotPromptBuilder $promptBuilder,
        private readonly AiToolSchemaBuilder $toolSchemaBuilder,
        private readonly BotToolRegistry $toolRegistry,
        private readonly ConversationBlockNormalizer $blockNormalizer,
        private readonly ConversationCycleLogger $cycleLogger,
    ) {}

    /**
     * @param  list<array{role: 'user'|'assistant', content: string}>  $history
     * @param  array<string, mixed>  $runtimeContext
     */
    public function run(
        Bot $bot,
        string $message,
        array $history = [],
        ?Conversation $conversation = null,
        ?Message $userMessage = null,
        array $runtimeContext = [],
        RuntimeMode $mode = RuntimeMode::Normal,
    ): AiSearchResponse {
        $context = $this->contextBuilder->build($bot);
        $registeredTools = $this->toolRegistry->forBot($bot);

        if ($context['datasets'] === [] && $registeredTools === []) {
            throw new AiException('This bot has no enabled datasets configured for search.');
        }

        $tools = array_map(
            fn (BotTool $tool): array => $this->toolSchemaBuilder->build($tool, $bot),
            $registeredTools,
        );
        $instructions = $this->promptBuilder->build($bot, $context);
        $executionContext = ToolExecutionContext::forBot(
            $bot,
            $conversation,
            $userMessage,
            runtimeContext: $runtimeContext,
            mode: $mode,
        );
        $input = [...$history, ['role' => 'user', 'content' => $message]];

        if ($runtimeContext !== []) {
            try {
                $encodedContext = json_encode($runtimeContext, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new AiException('The submitted form context was invalid.', previous: $exception);
            }

            $input[] = [
                'role' => 'user',
                'content' => 'Trusted form values submitted for the current request: '.$encodedContext,
            ];
        }
        $searches = [];
        $cardSources = [];
        $blocks = [];
        $toolOutcomes = [];
        $toolCallsCount = 0;
        $actionProposals = [];
        $usage = null;
        $maxRounds = max(1, (int) config('openai.max_tool_rounds', 3));
        $forceCatalogSearch = false;
        $hasCatalogSearchTool = collect($tools)->contains(
            fn (array $tool): bool => ($tool['name'] ?? null) === 'search_catalog',
        );

        for ($round = 0; $round < $maxRounds; $round++) {
            $this->cycleLogger->aiRequest([
                'round' => $round + 1,
                'model' => config('openai.model'),
                'tool_choice' => $forceCatalogSearch
                    ? ['type' => 'function', 'name' => 'search_catalog']
                    : 'auto',
                'parallel_tool_calls' => false,
                'tool_names' => array_map(
                    static fn (array $tool): string => (string) data_get($tool, 'name', 'unknown'),
                    $tools,
                ),
                'input_message_count' => count($input),
                'input' => config('chatbot_runtime.log_full_prompt', false) ? $input : null,
                'instructions' => config('chatbot_runtime.log_full_prompt', false) ? $instructions : null,
                'instructions_sha256' => hash('sha256', $instructions),
                'instructions_length' => strlen($instructions),
            ]);
            $aiStartedAt = hrtime(true);
            $response = $this->client->createResponse([
                'instructions' => $instructions,
                'input' => $input,
                'tools' => $tools,
                'tool_choice' => $forceCatalogSearch
                    ? ['type' => 'function', 'name' => 'search_catalog']
                    : 'auto',
                'parallel_tool_calls' => false,
            ]);
            $usage = $response['usage'] ?? $usage;
            $output = $response['output'];
            $toolCalls = array_values(array_filter(
                $output,
                static fn (array $item): bool => ($item['type'] ?? null) === 'function_call',
            ));
            $this->cycleLogger->aiResponse([
                'round' => $round + 1,
                'duration_ms' => (int) ((hrtime(true) - $aiStartedAt) / 1_000_000),
                'provider_response_id' => $response['id'] ?? null,
                'status' => $response['status'] ?? null,
                'finish_reason' => $response['finish_reason'] ?? null,
                'output_text' => $response['output_text'] ?? null,
                'tool_calls' => array_map(fn (array $toolCall): array => [
                    'call_id' => $toolCall['call_id'] ?? null,
                    'name' => $toolCall['name'] ?? null,
                    'arguments' => $this->decodedArguments($toolCall['arguments'] ?? null),
                ], $toolCalls),
                'usage' => $response['usage'] ?? null,
            ]);

            if ($toolCalls === []) {
                $answer = $this->answer($response);

                if ($answer === '') {
                    throw new AiException('OpenAI returned no final answer.');
                }

                if ($hasCatalogSearchTool
                    && $this->looksLikeNoResultsClaim($answer)
                    && ! $this->hasCompletedEmptyCatalogSearch($toolOutcomes)) {
                    if (! $forceCatalogSearch) {
                        $forceCatalogSearch = true;
                        $this->cycleLogger->event('grounding.enforcement.retry', [
                            'reason' => 'The model returned a catalog-dependent no-results claim without search_catalog.',
                            'answer' => $answer,
                        ], 'warning');
                        $input[] = [
                            'role' => 'user',
                            'content' => 'The current request requires live catalog verification. Call search_catalog now; do not answer from memory or history.',
                        ];

                        continue;
                    }

                    return new AiSearchResponse(
                        $this->catalogUnavailableMessage($message),
                        $toolCallsCount,
                        $searches,
                        $usage,
                        $cardSources,
                        $blocks,
                        [['tool' => 'search_catalog', 'outcome' => 'non_knowledge_failure']],
                        $actionProposals,
                    );
                }

                if ($this->hasFailedCatalogSearch($toolOutcomes) && $this->looksLikeNoResultsClaim($answer)) {
                    $this->cycleLogger->event('grounding.enforcement.replaced_answer', [
                        'reason' => 'The model converted a failed catalog search into a no-results claim.',
                        'answer' => $answer,
                    ], 'warning');
                    $answer = $this->catalogUnavailableMessage($message);
                }

                return new AiSearchResponse(
                    $answer,
                    $toolCallsCount,
                    $searches,
                    $usage,
                    $cardSources,
                    $blocks,
                    $toolOutcomes,
                    $actionProposals,
                );
            }

            $input = [...$input, ...$output];

            foreach ($toolCalls as $toolCall) {
                $toolCallsCount++;
                $toolStartedAt = hrtime(true);
                $this->cycleLogger->toolStarted([
                    'tool' => $toolCall['name'] ?? 'unknown',
                    'call_id' => $toolCall['call_id'] ?? null,
                    'arguments' => $this->decodedArguments($toolCall['arguments'] ?? null),
                ]);
                $result = $this->executeToolCall($bot, $toolCall, $executionContext);
                $forceCatalogSearch = false;
                $toolOutcomes[] = $this->toolOutcome($toolCall, $result);
                $this->cycleLogger->toolCompleted([
                    'tool' => $toolCall['name'] ?? 'unknown',
                    'call_id' => $toolCall['call_id'] ?? null,
                    'duration_ms' => (int) ((hrtime(true) - $toolStartedAt) / 1_000_000),
                    'ok' => $result->data['ok'] ?? false,
                    'error' => $result->data['error'] ?? null,
                    'search_count' => is_array($result->data['search']['items'] ?? null)
                        ? count($result->data['search']['items'])
                        : null,
                    'live_read' => $result->metadata['live_read'] ?? null,
                    'selected_query' => $result->metadata['selected_query'] ?? null,
                    'search_attempts' => $result->metadata['attempts'] ?? null,
                ]);
                $actionProposal = $result->data['action_proposed'] ?? null;

                if (is_string($actionProposal) && $actionProposal !== '') {
                    $actionProposals[] = $actionProposal;
                }
                $blocks = [...$blocks, ...$this->blockNormalizer->normalize($result->blocks)];

                if (($result->data['handoff_status'] ?? null) === 'requested') {
                    return new AiSearchResponse(
                        answer: ConversationHandoffService::REQUESTED_MESSAGE,
                        toolCallsCount: $toolCallsCount,
                        searches: $searches,
                        usage: $usage,
                        cardSources: $cardSources,
                        blocks: [],
                        toolOutcomes: $toolOutcomes,
                        actionProposals: $actionProposals,
                    );
                }

                $search = $result->data['search'] ?? null;

                if (is_array($search)) {
                    $searches[] = $search;
                }

                $cardSourcesMetadata = $result->metadata['card_sources'] ?? null;

                if (is_array($cardSourcesMetadata)) {
                    foreach ($cardSourcesMetadata as $cardSource) {
                        $this->appendCardSource($cardSources, $cardSource);
                    }
                } else {
                    $this->appendCardSource($cardSources, $result->metadata['card_source'] ?? null);
                }

                try {
                    $toolOutput = json_encode($result->modelData(), JSON_THROW_ON_ERROR);
                } catch (JsonException $exception) {
                    throw new AiException('The search tool returned an invalid result.', previous: $exception);
                }

                $this->cycleLogger->event('tool.result.prepared_for_model', [
                    'tool' => $toolCall['name'] ?? 'unknown',
                    'call_id' => $toolCall['call_id'] ?? null,
                    'result' => $result->modelData(),
                    'result_bytes' => strlen($toolOutput),
                    'result_mode' => 'full',
                ]);

                $input[] = [
                    'type' => 'function_call_output',
                    'call_id' => is_string($toolCall['call_id'] ?? null) ? $toolCall['call_id'] : '',
                    'output' => $toolOutput,
                ];
            }
        }

        $finalResponse = $this->client->createResponse([
            'instructions' => $instructions
                ."\nYou have already received the available tool results. Respond to the customer now using only those results. Do not call any tools.",
            'input' => [
                ...$input,
                [
                    'role' => 'user',
                    'content' => 'Give the final answer now. Do not call any tools.',
                ],
            ],
            'tools' => [],
        ]);
        $usage = $finalResponse['usage'] ?? $usage;
        $this->cycleLogger->aiResponse([
            'round' => $maxRounds + 1,
            'provider_response_id' => $finalResponse['id'] ?? null,
            'status' => $finalResponse['status'] ?? null,
            'finish_reason' => $finalResponse['finish_reason'] ?? null,
            'output_text' => $finalResponse['output_text'] ?? null,
            'tool_calls' => [],
            'usage' => $finalResponse['usage'] ?? null,
        ]);
        $answer = $this->answer($finalResponse);

        if ($answer !== '') {
            if ($this->hasFailedCatalogSearch($toolOutcomes) && $this->looksLikeNoResultsClaim($answer)) {
                $this->cycleLogger->event('grounding.enforcement.replaced_answer', [
                    'reason' => 'The model converted a failed catalog search into a no-results claim after the tool-call limit.',
                    'answer' => $answer,
                ], 'warning');
                $answer = $this->catalogUnavailableMessage($message);
            }

            return new AiSearchResponse(
                $answer,
                $toolCallsCount,
                $searches,
                $usage,
                $cardSources,
                $blocks,
                $toolOutcomes,
                $actionProposals,
            );
        }

        throw new AiException('The AI search exceeded its tool-call limit.');
    }

    /**
     * @param  array<string, mixed>  $toolCall
     * @return array{tool: string, outcome: string}
     */
    private function toolOutcome(array $toolCall, ToolResult $result): array
    {
        $tool = is_string($toolCall['name'] ?? null) ? $toolCall['name'] : 'unknown';

        if (($result->data['ok'] ?? null) !== true) {
            $error = is_string($result->data['error'] ?? null) ? $result->data['error'] : '';
            $nonKnowledgeFailure = $tool !== 'lookup_faq'
                && $tool !== 'search_catalog';

            if ($tool === 'search_catalog' || str_ends_with($error, '_unavailable') || $nonKnowledgeFailure) {
                return ['tool' => $tool, 'outcome' => 'non_knowledge_failure'];
            }

            return ['tool' => $tool, 'outcome' => 'invalid'];
        }

        if ($tool === 'lookup_faq') {
            $results = $result->data['results'] ?? null;

            return [
                'tool' => $tool,
                'outcome' => is_array($results) && $results !== []
                    ? 'knowledge_success'
                    : 'no_knowledge_match',
            ];
        }

        if ($tool === 'search_catalog') {
            $search = $result->data['search'] ?? null;
            $count = is_array($search) && is_int($search['count'] ?? null)
                ? $search['count']
                : (is_array($search['items'] ?? null) ? count($search['items']) : 0);

            return [
                'tool' => $tool,
                'outcome' => $count > 0 ? 'knowledge_success' : 'no_results',
            ];
        }

        return ['tool' => $tool, 'outcome' => 'irrelevant'];
    }

    /**
     * @param  list<array<string, mixed>>  $cardSources
     */
    private function appendCardSource(array &$cardSources, mixed $cardSource): void
    {
        if (! is_array($cardSource)) {
            return;
        }

        if (is_array($cardSource['live_items'] ?? null)) {
            $cardSources[] = ['live_items' => $cardSource['live_items']];

            return;
        }

        if (! is_int($cardSource['dataset_id'] ?? null)
            || ! is_array($cardSource['record_ids'] ?? null)) {
            return;
        }

        $cardSources[] = [
            'dataset_id' => $cardSource['dataset_id'],
            'record_ids' => array_values(array_filter(
                $cardSource['record_ids'],
                fn (mixed $recordId): bool => is_int($recordId),
            )),
        ];
    }

    /**
     * @param  array<string, mixed>  $toolCall
     */
    private function executeToolCall(
        Bot $bot,
        array $toolCall,
        ToolExecutionContext $context,
    ): ToolResult {
        if (! is_string($toolCall['call_id'] ?? null) || trim($toolCall['call_id']) === '') {
            throw new AiException('OpenAI returned an invalid tool call.');
        }

        $toolName = $toolCall['name'] ?? null;
        $tool = is_string($toolName)
            ? $this->toolRegistry->find($bot, $toolName)
            : null;

        if (! $tool instanceof BotTool) {
            return ToolResult::failure(
                'unsupported_tool',
                'The requested tool is not available.',
            );
        }

        try {
            $arguments = json_decode((string) ($toolCall['arguments'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return ToolResult::failure(
                'invalid_arguments',
                'The tool arguments were not valid JSON.',
            );
        }

        if (! is_array($arguments)) {
            return ToolResult::failure(
                'invalid_arguments',
                'The tool arguments were invalid.',
            );
        }

        return $tool->execute($bot, $arguments, $context);
    }

    /**
     * @param  array{output: list<array<string, mixed>>, output_text: string|null, usage: array<string, mixed>|null}  $response
     */
    private function answer(array $response): string
    {
        $refused = collect($response['output'])
            ->flatMap(fn (array $item): array => is_array($item['content'] ?? null) ? $item['content'] : [])
            ->contains(fn (array $content): bool => ($content['type'] ?? null) === 'refusal');

        if ($refused) {
            throw new AiException('The AI declined to answer this request.');
        }

        if (is_string($response['output_text']) && trim($response['output_text']) !== '') {
            return trim($response['output_text']);
        }

        $text = collect($response['output'])
            ->filter(fn (array $item): bool => ($item['type'] ?? null) === 'message')
            ->flatMap(fn (array $item): array => is_array($item['content'] ?? null) ? $item['content'] : [])
            ->filter(fn (array $content): bool => ($content['type'] ?? null) === 'output_text')
            ->pluck('text')
            ->filter(fn (mixed $text): bool => is_string($text))
            ->implode('');

        return trim($text);
    }

    private function decodedArguments(mixed $arguments): mixed
    {
        if (! is_string($arguments) || trim($arguments) === '') {
            return $arguments;
        }

        try {
            return json_decode($arguments, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return '[INVALID_JSON]';
        }
    }

    private function looksLikeNoResultsClaim(string $answer): bool
    {
        return Str::contains(
            Str::lower($answer),
            ['no products', 'not found', 'no matching', 'nothing available', 'არ მოიძებნა', 'ვერ მოიძებნა'],
        );
    }

    /** @param list<array{tool: string, outcome: string}> $toolOutcomes */
    private function hasFailedCatalogSearch(array $toolOutcomes): bool
    {
        foreach ($toolOutcomes as $outcome) {
            if ($outcome['tool'] === 'search_catalog' && $outcome['outcome'] === 'non_knowledge_failure') {
                return true;
            }
        }

        return false;
    }

    /** @param list<array{tool: string, outcome: string}> $toolOutcomes */
    private function hasCompletedEmptyCatalogSearch(array $toolOutcomes): bool
    {
        foreach ($toolOutcomes as $outcome) {
            if ($outcome['tool'] === 'search_catalog' && $outcome['outcome'] === 'no_results') {
                return true;
            }
        }

        return false;
    }

    private function catalogUnavailableMessage(string $message): string
    {
        return preg_match('/[\x{10A0}-\x{10FF}]/u', $message) === 1
            ? 'ბოდიში, ცოცხალი კატალოგის შემოწმება ამჟამად ვერ მოხერხდა.'
            : 'Sorry, the live catalog could not be checked right now.';
    }
}
