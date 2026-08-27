<?php

namespace App\Services\Ai\Tools;

use App\Models\ApiOperation;
use App\Models\Bot;
use App\Services\Ai\AiException;
use App\Services\Ai\BotRuntimeContextBuilder;
use App\Services\Ai\Formatters\AiSearchResultFormatter;
use App\Services\Ai\Mappers\AiSearchQueryFactory;
use App\Services\Ai\Tools\Contracts\BotTool;
use App\Services\Api\LiveRead\LiveReadQuery;
use App\Services\Api\LiveRead\LiveReadQueryPlanner;
use App\Services\Api\RuntimeApiOperationExecutor;
use App\Services\Api\RuntimeApiOperationResolver;
use App\Services\Search\Enums\SearchSortDirection;
use App\Services\Search\Exceptions\InvalidSearchCriteriaException;
use App\Services\Search\SearchService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Throwable;

class SearchCatalogTool implements BotTool
{
    public function __construct(
        private readonly AiSearchQueryFactory $queryFactory,
        private readonly SearchService $searchService,
        private readonly AiSearchResultFormatter $resultFormatter,
        private readonly BotRuntimeContextBuilder $contextBuilder,
        private readonly RuntimeApiOperationResolver $operationResolver,
        private readonly RuntimeApiOperationExecutor $operationExecutor,
        private readonly LiveReadQueryPlanner $liveReadPlanner,
    ) {}

    public function name(): string
    {
        return 'search_catalog';
    }

    public function description(): string
    {
        return 'Search one dataset attached to this bot using business search criteria.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(Bot $bot): array
    {
        $context = $this->contextBuilder->build($bot);
        $datasetSlugs = array_map(
            static fn (array $dataset): string => $dataset['slug'],
            $context['datasets'],
        );

        return [
            'type' => 'object',
            'properties' => [
                'dataset' => [
                    'type' => ['string', 'null'],
                    'enum' => [...$datasetSlugs, null],
                ],
                'text' => [
                    'type' => ['string', 'null'],
                    'description' => 'Use null for a broad product listing with no search phrase.',
                ],
                'filters' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'field' => ['type' => 'string'],
                            'operator' => [
                                'type' => 'string',
                                'enum' => ['eq', 'neq', 'contains', 'starts_with', 'ends_with', 'in', 'gt', 'gte', 'lt', 'lte', 'between', 'is_null', 'is_not_null'],
                            ],
                            'value' => ['type' => ['string', 'number', 'boolean', 'null']],
                        ],
                        'required' => ['field', 'operator', 'value'],
                        'additionalProperties' => false,
                    ],
                ],
                'sorts' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'field' => ['type' => 'string'],
                            'direction' => [
                                'type' => 'string',
                                'enum' => array_column(SearchSortDirection::cases(), 'value'),
                            ],
                        ],
                        'required' => ['field', 'direction'],
                        'additionalProperties' => false,
                    ],
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => min(max(1, (int) config('openai.max_results', 10)), 100),
                    'description' => 'Maximum number of products to return. Use 10 or less.',
                ],
                'result_count' => [
                    'type' => ['object', 'null'],
                    'properties' => [
                        'mode' => ['type' => 'string', 'enum' => ['default', 'exact', 'minimum', 'maximum', 'range', 'all']],
                        'value' => ['type' => ['integer', 'null'], 'minimum' => 1],
                        'minimum' => ['type' => ['integer', 'null'], 'minimum' => 1],
                        'maximum' => ['type' => ['integer', 'null'], 'minimum' => 1],
                    ],
                    'required' => ['mode', 'value', 'minimum', 'maximum'],
                    'additionalProperties' => false,
                    'description' => 'Use exact for a requested number, minimum for at least, maximum for at most, range for a lower and upper bound, or all for a bounded broad result.',
                ],
            ],
            'required' => ['dataset', 'text', 'filters', 'sorts', 'limit', 'result_count'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(Bot $bot, array $arguments, ToolExecutionContext $context): ToolResult
    {
        if ((int) $context->bot->id !== (int) $bot->id
            || (int) $context->team->id !== (int) $bot->team_id) {
            return ToolResult::failure(
                'invalid_search',
                'The search request could not be fulfilled. Use only authorized datasets and supported fields, operators, and values.',
            );
        }

        try {
            if ($this->shouldUseLive($bot, $arguments)) {
                return $this->executeLive($bot, $arguments);
            }

            $arguments = $this->preserveNativeSearchText($arguments, $context);
            ['dataset' => $dataset, 'query' => $query] = $this->queryFactory->make($bot, $arguments);
            $startedAt = hrtime(true);
            $result = $this->searchService->search($context->team, $query);
            $formatted = $this->resultFormatter->format($dataset, $result);

            if (! $context->isTest()) {
                try {
                    $bot->searchRuns()->create([
                        'dataset_id' => $dataset->id,
                        'conversation_id' => $context->conversation?->id,
                        'message_id' => $context->userMessage?->id,
                        'query' => $query->text ?? 'catalog search',
                        'intent' => [
                            'filters' => array_map(fn ($filter): array => [
                                'field' => $filter->field,
                                'operator' => $filter->operator->value,
                                'value' => $filter->value,
                            ], $query->filters),
                            'sorts' => array_map(fn ($sort): array => [
                                'field' => $sort->field,
                                'direction' => $sort->direction->value,
                            ], $query->sorts),
                        ],
                        'adapter' => config('search.engine'),
                        'request_payload' => ['limit' => $query->limit],
                        'result_count' => $result->total,
                        'latency_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
                        'status' => 'completed',
                    ]);
                } catch (Throwable $exception) {
                    logger()->warning('AI catalog search telemetry failed.', [
                        'bot_id' => $bot->id,
                        'team_id' => $bot->team_id,
                        'tool' => 'search_catalog',
                        'exception' => $exception::class,
                        'message' => $exception->getMessage(),
                    ]);
                }
            }

            return ToolResult::success(
                data: [
                    'ok' => true,
                    'search' => $formatted,
                ],
                metadata: [
                    'card_source' => [
                        'dataset_id' => (int) $dataset->id,
                        'record_ids' => array_map(
                            fn ($record): int => (int) $record->id,
                            $result->records,
                        ),
                    ],
                ],
            );
        } catch (AiException|InvalidSearchCriteriaException|ModelNotFoundException $exception) {
            logger()->notice('AI catalog search request was rejected.', [
                'bot_id' => $bot->id,
                'team_id' => $bot->team_id,
                'tool' => 'search_catalog',
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return ToolResult::failure(
                'invalid_search',
                'The search request could not be fulfilled. Use only authorized datasets and supported fields, operators, and values.',
            );
        } catch (Throwable $exception) {
            logger()->warning('AI catalog search failed.', [
                'bot_id' => $bot->id,
                'team_id' => $bot->team_id,
                'tool' => 'search_catalog',
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
            report($exception);

            return ToolResult::failure(
                'search_unavailable',
                'The catalog search is temporarily unavailable.',
            );
        }
    }

    /** @param array<string, mixed> $arguments */
    private function shouldUseLive(Bot $bot, array $arguments): bool
    {
        return ($arguments['dataset'] ?? null) === null
            && $this->operationResolver->resolveRead($bot, 'search_catalog') !== null;
    }

    /** @param array<string, mixed> $arguments */
    private function executeLive(Bot $bot, array $arguments): ToolResult
    {
        $operation = $this->operationResolver->resolveRead($bot, 'search_catalog');

        if ($operation === null) {
            return ToolResult::failure('search_unavailable', 'The catalog search is temporarily unavailable.');
        }

        $runtimeArguments = $this->liveArguments($operation->operation, $arguments);
        $plan = $this->liveReadPlanner->plan($operation->operation, LiveReadQuery::fromArguments($arguments), $runtimeArguments);
        $result = $this->operationExecutor->executeLiveRead($operation, $plan);

        if (! $result->success) {
            return ToolResult::failure($result->error ?? 'search_unavailable', $result->message ?? 'The catalog search is temporarily unavailable.');
        }

        $items = $this->normalizeLiveItems((array) ($result->data['records'] ?? []));

        return ToolResult::success(
            ['ok' => true, 'search' => ['dataset' => 'live', 'count' => count($items), 'items' => $items]],
            ['card_source' => ['live_items' => $items], 'live_read' => $result->data['meta'] ?? []],
        );
    }

    /** @return array<string, mixed> */
    private function liveArguments(ApiOperation $operation, array $arguments): array
    {
        $schema = (array) $operation->request_schema;
        $properties = (array) ($schema['properties'] ?? []);
        $mapped = [...(array) data_get($operation->request_mapping, 'query', []), ...(array) data_get($operation->request_mapping, 'body', [])];
        $result = [];

        foreach ($mapped as $argument => $_destination) {
            if (! is_string($argument) || ! isset($properties[$argument])) {
                continue;
            }

            $lower = strtolower($argument);
            if (in_array($lower, ['q', 'query', 'search', 'keyword', 'term', 'text', 'name', 'title', 'product_name'], true)) {
                $result[$argument] = $arguments['text'] ?? null;
            } elseif (in_array($lower, ['limit', 'per_page', 'page_size'], true)) {
                $result[$argument] = $arguments['limit'] ?? 10;
            } elseif (in_array($lower, ['category', 'category_name', 'category_id'], true)) {
                $result[$argument] = $this->liveFilterValue($arguments['filters'] ?? [], $lower);
            }
        }

        return array_filter($result, static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Translate the canonical catalog filter argument into a scalar API argument.
     */
    private function liveFilterValue(mixed $filters, string $mappedName): string|int|float|bool|null
    {
        if (! is_array($filters)) {
            return null;
        }

        foreach ($filters as $filter) {
            if (! is_array($filter) || ! is_string($filter['field'] ?? null)) {
                continue;
            }

            $field = strtolower($filter['field']);
            if ($field !== $mappedName && ! ($mappedName === 'category_name' && $field === 'category')) {
                continue;
            }

            $value = $filter['value'] ?? null;

            return is_scalar($value) ? $value : null;
        }

        return null;
    }

    /** @param array<int|string, mixed> $data @return list<array<string, mixed>> */
    private function normalizeLiveItems(array $data): array
    {
        $items = array_is_list($data) ? $data : collect(['items', 'products', 'results', 'data'])->first(fn (string $key): bool => is_array($data[$key] ?? null) && array_is_list($data[$key])) ?? [$data];

        return array_values(array_filter(array_map(function (mixed $item): ?array {
            if (! is_array($item)) {
                return null;
            }

            return [
                'id' => $item['id'] ?? $item['external_id'] ?? null,
                'title' => $item['title'] ?? $item['name'] ?? $item['product_name'] ?? null,
                'subtitle' => $item['subtitle'] ?? $item['brand'] ?? $item['category'] ?? null,
                'description' => $item['description'] ?? $item['summary'] ?? null,
                'price' => $item['price'] ?? $item['sale_price'] ?? null,
                'old_price' => $item['old_price'] ?? $item['original_price'] ?? $item['compare_price'] ?? null,
                'discount' => $item['discount'] ?? $item['discount_percent'] ?? null,
                'image_url' => $item['image_url'] ?? $item['image'] ?? $item['thumbnail_url'] ?? null,
                'product_url' => $item['product_url'] ?? $item['url'] ?? $item['link'] ?? null,
                'availability' => $item['availability'] ?? $item['stock'] ?? null,
                'badge' => $item['badge'] ?? null,
            ];
        }, $items), static fn (?array $item): bool => is_array($item) && is_scalar($item['title'] ?? null)));
    }

    /**
     * Preserve product terms written in a non-Latin script by the customer.
     * The model may translate those terms while selecting tool arguments, but
     * the indexed catalog contains the original product text.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function preserveNativeSearchText(array $arguments, ToolExecutionContext $context): array
    {
        $message = $context->userMessage?->content;

        if (! is_string($message) || trim($message) === '') {
            return $arguments;
        }

        preg_match_all(
            '/(?:[\p{Arabic}][\p{Arabic}\d\s\-]*|[\p{Armenian}][\p{Armenian}\d\s\-]*|[\p{Cyrillic}][\p{Cyrillic}\d\s\-]*|[\p{Georgian}][\p{Georgian}\d\s\-]*|[\p{Greek}][\p{Greek}\d\s\-]*|[\p{Hebrew}][\p{Hebrew}\d\s\-]*|[\p{Han}\p{Hiragana}\p{Katakana}][\p{Han}\p{Hiragana}\p{Katakana}\d\s\-]*)/u',
            $message,
            $matches,
        );

        $stopWords = [
            'გთხოვ',
            'მაჩვენე',
            'მიპოვე',
            'მომიძებნე',
            'მომეცი',
            'მინდა',
            'პროდუქტი',
            'პროდუქტები',
            'მაჩვენეთ',
        ];
        $nativeTerms = [];

        foreach ($matches[0] ?? [] as $term) {
            $words = preg_split('/\s+/u', trim($term), -1, PREG_SPLIT_NO_EMPTY);
            $words = array_values(array_filter(
                $words ?: [],
                static fn (string $word): bool => ! in_array(mb_strtolower($word), $stopWords, true)
                    && preg_match('/\p{L}/u', $word) === 1,
            ));

            if ($words !== []) {
                $nativeTerms[] = implode(' ', $words);
            }
        }

        if ($nativeTerms === []) {
            return $arguments;
        }

        usort(
            $nativeTerms,
            static fn (string $left, string $right): int => count(preg_split('/\s+/u', $right, -1, PREG_SPLIT_NO_EMPTY) ?: []) <=> count(preg_split('/\s+/u', $left, -1, PREG_SPLIT_NO_EMPTY) ?: []),
        );
        $arguments['text'] = $nativeTerms[0];

        return $arguments;
    }
}
