<?php

namespace App\Services\Conversations;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Ai\AiSearchResponse;
use App\Services\Logging\LogContextSanitizer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class ConversationCycleLogger
{
    private ?string $cycleId = null;

    /** @var array<string, mixed> */
    private array $context = [];

    /** @var array<string, mixed> */
    private array $summary = [];

    public function __construct(private readonly LogContextSanitizer $sanitizer) {}

    /** @param list<string> $availableTools */
    public function start(
        Bot $bot,
        Conversation $conversation,
        Message $userMessage,
        string $source,
        array $availableTools,
        bool $liveCatalog,
    ): string {
        $this->cycleId = 'chat_'.Str::lower((string) Str::ulid());
        $this->context = [
            'cycle_id' => $this->cycleId,
            'conversation_id' => $conversation->id,
            'bot_id' => $bot->id,
            'team_id' => $bot->team_id,
            'user_message_id' => $userMessage->id,
        ];
        $this->summary = [
            'ai_calls' => 0,
            'tool_calls' => 0,
            'api_requests' => 0,
            'available_tools' => $availableTools,
            'live_catalog_configured' => $liveCatalog,
        ];

        Log::shareContext($this->context);
        $this->event('conversation_cycle.started', [
            'source' => $source,
            'customer_message' => $userMessage->content,
            'available_tools' => $availableTools,
            'live_catalog_configured' => $liveCatalog,
        ]);

        return $this->cycleId;
    }

    public function active(): bool
    {
        return $this->cycleId !== null;
    }

    /** @param array<string, mixed> $data */
    public function event(string $event, array $data = [], string $level = 'info'): void
    {
        if (! $this->active() || ! config('chatbot_runtime.enabled', true)) {
            return;
        }

        $sanitizedData = $this->sanitizer->sanitize($data);

        if (is_array($sanitizedData)) {
            foreach (['result', 'response_body', 'input', 'instructions'] as $largeField) {
                if (! array_key_exists($largeField, $sanitizedData) || $sanitizedData[$largeField] === null) {
                    continue;
                }

                $encoded = $this->sanitizer->json($sanitizedData[$largeField]);
                $sanitizedData[$largeField] = $encoded['body'];
                $sanitizedData[$largeField.'_truncated'] = $encoded['truncated'];
                $sanitizedData[$largeField.'_original_bytes'] = $encoded['original_bytes'];
            }

            foreach ([
                'raw_response_item_count',
                'collection_extracted_item_count',
                'mapped_item_count',
                'deduplicated_candidate_count',
                'matcher_input_count',
                'matcher_output_count',
                'candidate_budget_clipped_count',
                'product_mapped_count',
                'product_card_count',
                'status',
            ] as $summaryField) {
                if (array_key_exists($summaryField, $sanitizedData)) {
                    $this->summary[$summaryField] = $sanitizedData[$summaryField];
                }
            }
        }

        $payload = [
            ...$this->context,
            'event' => $event,
            ...(is_array($sanitizedData) ? $sanitizedData : []),
        ];

        Log::channel((string) config('chatbot_runtime.channel', 'chatbot_runtime'))
            ->log($level, $event, $payload);
    }

    /** @param array<string, mixed> $data */
    public function aiRequest(array $data): void
    {
        $this->summary['ai_calls'] = ((int) ($this->summary['ai_calls'] ?? 0)) + 1;
        $this->event('ai.request.prepared', $data);
    }

    /** @param array<string, mixed> $data */
    public function aiResponse(array $data): void
    {
        $this->event('ai.response.received', $data);
    }

    /** @param array<string, mixed> $data */
    public function toolStarted(array $data): void
    {
        $this->summary['tool_calls'] = ((int) ($this->summary['tool_calls'] ?? 0)) + 1;
        $this->event('tool.execution.started', $data);
    }

    /** @param array<string, mixed> $data */
    public function toolCompleted(array $data): void
    {
        if (array_key_exists('selected_query', $data) && $data['selected_query'] !== null) {
            $this->summary['selected_query'] = $data['selected_query'];
        }

        if (is_array($data['search_attempts'] ?? null)) {
            $this->summary['search_attempts'] = $data['search_attempts'];
        }

        $this->event('tool.execution.completed', $data);
    }

    /** @param array<string, mixed> $data */
    public function apiRequest(array $data): void
    {
        $this->summary['api_requests'] = ((int) ($this->summary['api_requests'] ?? 0)) + 1;
        $this->event('live_api.request', $data);
    }

    /** @param array<string, mixed> $data */
    public function apiResponse(array $data): void
    {
        $this->event('live_api.response', $data);
    }

    public function finalAnswer(AiSearchResponse $response, bool $liveCatalog): void
    {
        $outcomes = $response->toolOutcomes;
        $searchOutcome = null;

        foreach ($outcomes as $outcome) {
            if ($outcome['tool'] === 'search_catalog') {
                $searchOutcome = $outcome;
            }
        }
        $answerReason = 'direct_conversation';
        $groundingSource = null;

        if (is_array($searchOutcome)) {
            $groundingSource = 'search_catalog';
            $answerReason = match ($searchOutcome['outcome']) {
                'knowledge_success' => $liveCatalog ? 'live_catalog_results' : 'catalog_results',
                'no_results' => $liveCatalog ? 'live_catalog_empty' : 'catalog_empty',
                'non_knowledge_failure' => $liveCatalog ? 'live_catalog_error' : 'catalog_error',
                default => $liveCatalog ? 'live_catalog_invalid_request' : 'catalog_invalid_request',
            };
        } elseif ($liveCatalog && $this->looksLikeUnsupportedCatalogClaim($response->answer)) {
            $answerReason = 'unsupported_model_answer';
            $this->event('grounding.warning', [
                'warning' => 'The model made a catalog-dependent claim without a search_catalog execution.',
                'answer' => $response->answer,
            ], 'warning');
        }

        $this->event('conversation_cycle.answer', [
            'answer' => $response->answer,
            'answer_language' => $this->language($response->answer),
            'answer_reason' => $answerReason,
            'grounding_source' => $groundingSource,
            'tool_outcomes' => $outcomes,
        ]);
        $this->summary['answer_reason'] = $answerReason;
        $this->summary['grounding_source'] = $groundingSource;
    }

    /** @param array<string, mixed> $data */
    public function complete(array $data = []): void
    {
        $this->summary = [...$this->summary, ...$data];
        $this->event('conversation_cycle.completed', ['summary' => $this->summary]);
    }

    public function failed(Throwable $exception): void
    {
        $this->event('conversation_cycle.failed', [
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'summary' => $this->summary,
        ], 'error');
    }

    public function clear(): void
    {
        if ($this->context !== []) {
            Log::withoutContext(array_keys($this->context));
        }

        $this->cycleId = null;
        $this->context = [];
        $this->summary = [];
    }

    private function looksLikeUnsupportedCatalogClaim(string $answer): bool
    {
        return Str::contains(Str::lower($answer), [
            'no products',
            'not found',
            'no matching',
            'nothing available',
            'არ მოიძებნა',
            'ვერ მოიძებნა',
        ]);
    }

    private function language(string $text): string
    {
        return preg_match('/[\x{10A0}-\x{10FF}]/u', $text) === 1 ? 'ka' : 'unknown';
    }
}
