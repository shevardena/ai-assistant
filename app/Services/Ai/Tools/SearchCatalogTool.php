<?php

namespace App\Services\Ai\Tools;

use App\Enums\PriceSemanticRole;
use App\Models\ApiOperation;
use App\Models\Bot;
use App\Models\Dataset;
use App\Models\DatasetField;
use App\Models\DatasetRecord;
use App\Services\Ai\AiException;
use App\Services\Ai\BotRuntimeContextBuilder;
use App\Services\Ai\CatalogSearchSourceResolver;
use App\Services\Ai\Formatters\AiSearchResultFormatter;
use App\Services\Ai\Mappers\AiSearchQueryFactory;
use App\Services\Ai\OriginalCatalogSearchTermExtractor;
use App\Services\Ai\Tools\Contracts\BotTool;
use App\Services\Api\LiveRead\LiveReadQuery;
use App\Services\Api\LiveRead\LiveReadQueryPlanner;
use App\Services\Api\LiveRead\LiveReadRecordMatcher;
use App\Services\Api\RuntimeApiOperation;
use App\Services\Api\RuntimeApiOperationExecutor;
use App\Services\Api\RuntimeApiOperationResolver;
use App\Services\Conversations\ConversationCycleLogger;
use App\Services\Search\Data\SearchQuery;
use App\Services\Search\Data\SearchResult;
use App\Services\Search\Enums\SearchSortDirection;
use App\Services\Search\Exceptions\InvalidSearchCriteriaException;
use App\Services\Search\SearchService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
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
        private readonly ConversationCycleLogger $cycleLogger,
        private readonly CatalogSearchSourceResolver $sourceResolver,
        private readonly OriginalCatalogSearchTermExtractor $originalTermExtractor,
        private readonly LiveReadRecordMatcher $recordMatcher,
    ) {}

    public function name(): string
    {
        return 'search_catalog';
    }

    public function description(): string
    {
        return 'Search connected product catalogs using concise, canonical product terms and supported business search criteria.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(Bot $bot): array
    {
        $context = $this->contextBuilder->build($bot);
        $datasetSlugs = array_map(
            static fn (array $dataset): string => $dataset['slug'],
            array_values(array_filter(
                $context['datasets'],
                static fn (array $dataset): bool => ! in_array($dataset['entityType'], ['faq', 'knowledge'], true),
            )),
        );

        return [
            'type' => 'object',
            'properties' => [
                'dataset' => [
                    'type' => ['string', 'null'],
                    'enum' => [...$datasetSlugs, null],
                    'description' => 'Optional preferred catalog slug. This is a hint by default and does not restrict the search unless source_scope is specific.',
                ],
                'source_scope' => [
                    'type' => ['string', 'null'],
                    'enum' => ['all', 'specific', null],
                    'description' => 'Use specific only when the customer explicitly asks for one named catalog; otherwise use all or null.',
                ],
                'text' => [
                    'type' => ['string', 'null'],
                    'description' => 'Use concise catalog-friendly terms, canonical names, or exact identifiers; do not copy the customer\'s full request. Use null for a broad product listing with no search phrase.',
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
                            'minimum' => ['type' => ['number', 'null']],
                            'maximum' => ['type' => ['number', 'null']],
                        ],
                        'required' => ['field', 'operator', 'value', 'minimum', 'maximum'],
                        'additionalProperties' => false,
                    ],
                ],
                'constraints' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'field' => ['type' => 'string', 'description' => 'Semantic constraint field such as year, brand, category, or product_type.'],
                            'operator' => [
                                'type' => 'string',
                                'enum' => ['eq', 'gte', 'lte'],
                            ],
                            'value' => ['type' => 'string'],
                        ],
                        'required' => ['field', 'operator', 'value'],
                        'additionalProperties' => false,
                    ],
                    'description' => 'Semantic product constraints. Keep these separate from the core catalog search text.',
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
            'required' => ['dataset', 'source_scope', 'text', 'filters', 'constraints', 'sorts', 'limit', 'result_count'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(Bot $bot, array $arguments, ToolExecutionContext $context): ToolResult
    {
        $rawArguments = $arguments;
        $canonicalSearchText = $this->searchText($arguments['text'] ?? null);
        $constraints = LiveReadQuery::normalizeConstraints($arguments['constraints'] ?? []);
        $hasSearchText = $canonicalSearchText !== null;
        $literalSearchText = $hasSearchText
            ? ($this->originalTermExtractor->extractLiteral($context->userMessage?->content)
                ?? $this->originalTermExtractor->extractLiteral($canonicalSearchText))
            : null;
        $relaxedSearchText = $hasSearchText ? $this->relaxedSearchText($canonicalSearchText) : null;
        $originalSearchText = $hasSearchText
            ? $this->originalTermExtractor->extract($context->userMessage?->content)
            : null;
        $searchCandidates = $this->searchCandidates($literalSearchText, $originalSearchText, $canonicalSearchText, $relaxedSearchText);
        $searchMode = $literalSearchText !== null
            ? 'literal'
            : ($hasSearchText || $constraints !== [] ? 'semantic' : 'browse');
        $arguments = [
            ...$arguments,
            'filters' => $this->normalizeFilterValues($arguments['filters'] ?? []),
            'constraints' => $constraints,
        ];

        $this->cycleLogger->event('search_catalog.intent.resolved', [
            'original_message' => Str::limit($context->userMessage === null ? '' : (string) $context->userMessage->content, 1000),
            'search_mode' => $searchMode,
            'literal_search_text' => $literalSearchText,
            'original_search_text' => $originalSearchText,
            'canonical_search_text' => $canonicalSearchText,
            'relaxed_search_text' => $relaxedSearchText,
            'constraints' => $constraints,
        ]);

        $this->cycleLogger->event('search_catalog.invoked', [
            'bot_id' => $bot->id,
            'team_id' => $bot->team_id,
            'tool' => 'search_catalog',
            'original_customer_message' => Str::limit($context->userMessage === null ? '' : (string) $context->userMessage->content, 1000),
            'tool_arguments' => [
                'dataset' => $rawArguments['dataset'] ?? null,
                'source_scope' => $rawArguments['source_scope'] ?? null,
                'text' => $rawArguments['text'] ?? null,
                'filters' => $rawArguments['filters'] ?? [],
                'constraints' => $rawArguments['constraints'] ?? [],
                'sorts' => $rawArguments['sorts'] ?? [],
                'limit' => $rawArguments['limit'] ?? null,
                'result_count' => $rawArguments['result_count'] ?? null,
            ],
            'search_mode' => $searchMode,
            'literal_search_text' => $literalSearchText,
            'original_search_text' => $originalSearchText,
            'canonical_search_text' => $canonicalSearchText,
            'relaxed_search_text' => $relaxedSearchText,
            'constraints' => $constraints,
            'normalized_search_text' => $canonicalSearchText,
        ]);

        if ((int) $context->bot->id !== (int) $bot->id
            || (int) $context->team->id !== (int) $bot->team_id) {
            return ToolResult::failure(
                'invalid_search',
                'The search request could not be fulfilled. Use only authorized datasets and supported fields, operators, and values.',
            );
        }

        try {
            $requestedDataset = is_string($arguments['dataset'] ?? null)
                ? $arguments['dataset']
                : null;
            $sourceScope = ($arguments['source_scope'] ?? null) === 'specific' && $requestedDataset !== null
                ? 'specific'
                : 'all';
            $resolution = $this->sourceResolver->resolve(
                $bot,
                $requestedDataset,
                restrictToRequested: $sourceScope === 'specific',
            );
            $selectionMode = $sourceScope === 'specific'
                ? 'explicit_source'
                : (count($resolution['eligible']) > 1 ? 'federated' : 'single_available_source');
            $this->cycleLogger->event('search_catalog.sources.resolved', [
                'selection_mode' => $selectionMode,
                'requested_dataset' => $sourceScope === 'specific' ? $requestedDataset : null,
                'preferred_dataset' => $sourceScope === 'all' ? $requestedDataset : null,
                'requested_dataset_treated_as' => $sourceScope === 'specific' ? 'explicit_source' : ($requestedDataset === null ? null : 'hint'),
                'source_scope' => $sourceScope,
                'eligible_sources' => array_map(fn (array $source): array => $this->sourceLog($source), $resolution['eligible']),
                'rejected_sources' => $resolution['rejected'],
            ]);

            if ($resolution['eligible'] === []) {
                return ToolResult::failure('search_unavailable', 'No product catalog is configured for this bot.');
            }

            if (count($resolution['eligible']) === 1) {
                $source = $resolution['eligible'][0];

                return ($source['type'] ?? null) === 'api_operation'
                    ? $this->executeLive($bot, $arguments, $searchCandidates, $source)
                    : $this->executeDataset($bot, $arguments, $context, $source, $searchCandidates);
            }

            return $this->executeFederated($bot, $arguments, $context, $resolution['eligible'], $searchCandidates);
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

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $source
     * @param  list<array{type: string, text: string|null, reason: string}>  $searchCandidates
     */
    private function executeDataset(
        Bot $bot,
        array $arguments,
        ToolExecutionContext $context,
        array $source,
        array $searchCandidates,
    ): ToolResult {
        $dataset = $source['dataset'] ?? null;

        if (! $dataset instanceof Dataset) {
            return ToolResult::failure('invalid_search', 'The selected product catalog is unavailable.');
        }

        $datasetFields = $dataset->fields()->get();
        $this->cycleLogger->event('search_catalog.price_semantics.resolved', [
            'source' => $this->sourceLog($source),
            'requested_fields' => $this->datasetPriceResolution($datasetFields),
        ]);
        $derivedDiscountFilters = [];
        $hasExplicitDiscountField = $datasetFields->contains(
            fn ($field): bool => PriceSemanticRole::normalize($field->semantic_type, $field->key) === PriceSemanticRole::DiscountPercent,
        );
        $hasDiscountPair = $datasetFields->contains(
            fn ($field): bool => PriceSemanticRole::normalize($field->semantic_type, $field->key) === PriceSemanticRole::CurrentPrice,
        ) && $datasetFields->contains(
            fn ($field): bool => PriceSemanticRole::normalize($field->semantic_type, $field->key) === PriceSemanticRole::RegularPrice,
        );
        $baseFilters = [];
        foreach ((array) ($arguments['filters'] ?? []) as $filter) {
            if (is_array($filter) && ($filter['field'] ?? null) === PriceSemanticRole::DiscountPercent->value && ! $hasExplicitDiscountField) {
                if (! $hasDiscountPair) {
                    return ToolResult::failure('unsupported_search_criteria', 'This catalog does not expose discount pricing data.');
                }
                $derivedDiscountFilters[] = $filter;

                continue;
            }

            $baseFilters[] = $filter;
        }

        if ($derivedDiscountFilters !== []) {
            $currentField = $datasetFields->first(fn ($field): bool => PriceSemanticRole::normalize($field->semantic_type, $field->key) === PriceSemanticRole::CurrentPrice);
            $regularField = $datasetFields->first(fn ($field): bool => PriceSemanticRole::normalize($field->semantic_type, $field->key) === PriceSemanticRole::RegularPrice);
            $this->cycleLogger->event('search_catalog.price_semantics.resolved', [
                'source' => $this->sourceLog($source),
                'requested_field' => PriceSemanticRole::DiscountPercent->value,
                'discount_source' => 'derived',
                'current_field' => $currentField?->key,
                'regular_field' => $regularField?->key,
            ]);
        }

        $attempts = [];
        /** @var SearchQuery|null $query */
        $query = null;
        /** @var SearchResult|null $result */
        $result = null;
        /** @var array{dataset: string, count: int, items: list<array<string, mixed>>}|null $formatted */
        $formatted = null;
        $startedAt = hrtime(true);
        $selectedCandidate = $searchCandidates[0] ?? ['type' => 'browse', 'text' => null, 'reason' => 'browse_all_or_empty_search_text'];
        $semanticConstraints = array_values(array_filter((array) ($arguments['constraints'] ?? []), 'is_array'));
        $candidateTotal = null;

        foreach ($searchCandidates as $attemptIndex => $candidate) {
            $attemptConstraints = $candidate['type'] === 'literal' ? [] : $semanticConstraints;
            $attemptArguments = [
                ...$arguments,
                'dataset' => $dataset->slug,
                'text' => $candidate['text'],
                'filters' => $baseFilters,
                'constraints' => $attemptConstraints,
            ];
            if ($attemptConstraints !== [] || $derivedDiscountFilters !== []) {
                $attemptArguments['candidate_limit'] = min(100, max(100, (int) ($arguments['limit'] ?? 10)));
            }
            ['query' => $query] = $this->queryFactory->make($bot, $attemptArguments);
            $startedAt = hrtime(true);
            $result = $this->searchService->search($context->team, $query);
            $candidateTotal = $result->total;
            if ($attemptConstraints !== [] || $derivedDiscountFilters !== []) {
                $candidatesBefore = count($result->records);
                $fields = $datasetFields->mapWithKeys(fn ($field): array => [
                    $field->key => [
                        'type' => $field->data_type,
                        'searchable' => (bool) $field->is_searchable,
                        'displayable' => (bool) $field->is_displayable,
                    ],
                ])->all();
                $fields['searchable_text'] = ['type' => 'string', 'searchable' => true, 'displayable' => false];
                if ($hasDiscountPair) {
                    $currentField = $datasetFields->first(fn ($field): bool => PriceSemanticRole::normalize($field->semantic_type, $field->key) === PriceSemanticRole::CurrentPrice);
                    $regularField = $datasetFields->first(fn ($field): bool => PriceSemanticRole::normalize($field->semantic_type, $field->key) === PriceSemanticRole::RegularPrice);
                    if ($currentField !== null && $regularField !== null) {
                        $fields[PriceSemanticRole::DiscountPercent->value] = [
                            'type' => 'decimal',
                            'derived_from' => [
                                'current_price' => $currentField->key,
                                'regular_price' => $regularField->key,
                            ],
                        ];
                    }
                }
                $records = array_values(array_filter(
                    $result->records,
                    function ($record) use ($attemptConstraints, $derivedDiscountFilters, $fields): bool {
                        $values = $this->datasetRecordValues($record);

                        return $this->recordMatcher->matchesConstraints($values, $attemptConstraints, $fields)
                            && $this->recordMatcher->matches($values, $derivedDiscountFilters, $fields);
                    },
                ));
                $result = new SearchResult($records, count($records));
                $this->cycleLogger->event('search_catalog.local_constraints.matched', [
                    'constraints' => $attemptConstraints,
                    'candidates_before' => $candidatesBefore,
                    'candidates_after' => count($records),
                ]);
            }
            $formatted = $this->resultFormatter->format($dataset, $result);
            $count = count($result->records);
            $hasNextCandidate = isset($searchCandidates[$attemptIndex + 1]);
            $confirmedEmpty = $count === 0;
            $fallbackTriggered = $confirmedEmpty && $hasNextCandidate;
            $attempt = [
                'type' => $candidate['type'],
                'text' => $candidate['text'],
                'count' => $count,
                'confirmed_empty' => $confirmedEmpty,
                'http_status' => null,
                'fallback_triggered' => $fallbackTriggered,
            ];
            $attempts[] = $attempt;
            $this->logSearchAttempt($source, $attemptIndex + 1, $candidate, $count, $confirmedEmpty, null, $fallbackTriggered);
            $selectedCandidate = $candidate;

            if (! $fallbackTriggered) {
                break;
            }
        }

        if ($query === null || $result === null || $formatted === null) {
            return ToolResult::failure('search_unavailable', 'The catalog search is temporarily unavailable.');
        }

        $formatted['sources'] = [[
            ...$this->sourceLog($source),
            'count' => count($formatted['items']),
        ]];
        $pricing = [
            'semantic_field' => PriceSemanticRole::CurrentPrice->value,
            'resolved_fields' => $this->datasetPriceResolution($datasetFields),
            'currency_consistent' => ($currency = $this->sourceCurrency($source)) === null ? null : $currency !== 'mixed',
            'currency_conversion' => 'not_applied',
        ];
        $filtersComplete = $semanticConstraints === [] && $derivedDiscountFilters === []
            || ($candidateTotal !== null && $candidateTotal <= $query->limit);
        $formatted['execution'] = [
            'filters' => [
                'remote' => [],
                'local' => $derivedDiscountFilters,
                'unsupported' => [],
                'indexed' => $query->filters,
            ],
            'constraints' => [
                'remote' => [],
                'local' => $semanticConstraints,
                'unsupported' => [],
            ],
            'sort' => [
                'mode' => 'indexed',
                'global_guaranteed' => true,
            ],
            'complete' => $filtersComplete,
            'filters_complete' => $filtersComplete,
            'sort_complete' => true,
            'more_available' => ! $filtersComplete,
        ];

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
                'search' => [
                    ...$formatted,
                    'outcome' => count($formatted['items']) > 0 ? 'catalog_success' : 'no_results',
                ],
            ],
            metadata: [
                'attempts' => $attempts,
                'selected_query' => $selectedCandidate['text'],
                'card_source' => [
                    'dataset_id' => (int) $dataset->id,
                    'record_ids' => array_map(
                        fn ($record): int => (int) $record->id,
                        $result->records,
                    ),
                ],
                'pricing' => $pricing,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  list<array<string, mixed>>  $sources
     * @param  list<array{type: string, text: string|null, reason: string}>  $searchCandidates
     */
    private function executeFederated(
        Bot $bot,
        array $arguments,
        ToolExecutionContext $context,
        array $sources,
        array $searchCandidates,
    ): ToolResult {
        $pricing = $this->federatedPricing($sources);
        if ($this->hasPriceCriteria($arguments) && $pricing['currency_consistent'] === false) {
            return ToolResult::failure(
                'unsupported_search_criteria',
                'Price comparisons across these catalogs cannot be verified because their currencies differ.',
                ['pricing' => $pricing],
            );
        }

        $items = [];
        $cardSources = [];
        $sourceResults = [];
        $sourceProvenance = [];
        $sourceExecutions = [];
        $sourceErrors = [];
        $attempts = [];
        $selectedQueries = [];

        foreach ($sources as $source) {
            $this->cycleLogger->event('search_catalog.source.started', [
                'source' => $this->sourceLog($source),
                'candidate_count' => count($searchCandidates),
            ]);

            try {
                $result = ($source['type'] ?? null) === 'api_operation'
                    ? $this->executeLive($bot, $arguments, $searchCandidates, $source)
                    : $this->executeDataset($bot, $arguments, $context, $source, $searchCandidates);
            } catch (Throwable $exception) {
                logger()->warning('Catalog source search failed during federation.', [
                    ...$this->sourceLog($source),
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
                $sourceErrors[] = [
                    ...$this->sourceLog($source),
                    'error' => 'search_unavailable',
                ];
                $sourceProvenance[] = [
                    ...$this->sourceLog($source),
                    'count' => null,
                ];
                $sourceExecutions[] = [
                    'source' => $this->sourceLog($source),
                    'complete' => false,
                    'filters_complete' => false,
                    'sort_complete' => false,
                    'more_available' => true,
                ];
                $this->cycleLogger->event('search_catalog.source.completed', [
                    'source' => $this->sourceLog($source),
                    'status' => 'failed',
                    'result_count' => null,
                    'error' => 'search_unavailable',
                ], 'warning');

                continue;
            }

            foreach (($result->metadata['attempts'] ?? []) as $attempt) {
                if (is_array($attempt)) {
                    $attempts[] = [...$attempt, 'source' => $this->sourceLog($source)];
                }
            }
            if (array_key_exists('selected_query', $result->metadata)) {
                $selectedQueries[] = [
                    'source' => $this->sourceLog($source),
                    'query' => $result->metadata['selected_query'],
                ];
            }

            if (($result->data['ok'] ?? false) !== true) {
                $error = $result->data['error'] ?? 'search_unavailable';
                $sourceErrors[] = [
                    ...$this->sourceLog($source),
                    'error' => $error,
                ];
                $sourceProvenance[] = [
                    ...$this->sourceLog($source),
                    'count' => null,
                ];
                $sourceExecutions[] = [
                    'source' => $this->sourceLog($source),
                    'complete' => false,
                    'filters_complete' => false,
                    'sort_complete' => false,
                    'more_available' => true,
                ];
                $this->cycleLogger->event('search_catalog.source.completed', [
                    'source' => $this->sourceLog($source),
                    'status' => 'failed',
                    'result_count' => null,
                    'error' => $error,
                ], 'warning');

                continue;
            }

            $search = $result->data['search'] ?? [];
            $sourceItems = $this->searchItems(is_array($search) ? ($search['items'] ?? null) : null);
            $sourceItems = $this->normalizeSourceItems($sourceItems, $source);
            $sourceIdentity = ($source['type'] ?? 'source').':'.(string) ($source['id'] ?? 'unknown');
            $sourceResults[] = [
                ...$this->sourceLog($source),
                'count' => count($sourceItems),
            ];
            $sourceProvenance[] = [
                ...$this->sourceLog($source),
                'count' => count($sourceItems),
            ];
            if (is_array($search['execution'] ?? null)) {
                $sourceExecutions[] = [
                    'source' => $this->sourceLog($source),
                    'execution' => $search['execution'],
                ];
            }
            $this->cycleLogger->event('search_catalog.source.completed', [
                'source' => $this->sourceLog($source),
                'status' => 'completed',
                'result_count' => count($sourceItems),
            ]);
            $items = [
                ...$items,
                ...array_map(
                    static fn (array $item): array => [...$item, '_source_identity' => $sourceIdentity],
                    $sourceItems,
                ),
            ];
            foreach (($result->metadata['card_sources'] ?? []) as $cardSource) {
                if (is_array($cardSource)) {
                    $cardSources[] = $cardSource;
                }
            }
            if (is_array($result->metadata['card_source'] ?? null)) {
                $cardSources[] = $result->metadata['card_source'];
            }
        }

        $mergedCandidateCount = count($items);
        $items = $this->deduplicateItems($items);
        $deduplicatedCount = count($items);
        $items = array_map(static function (array $item): array {
            unset($item['_source_identity']);

            return $item;
        }, $items);
        $items = $this->sortFederatedItems($items, LiveReadQuery::fromArguments($arguments)->sorts);

        if ($sourceResults === [] && $sourceErrors !== []) {
            return ToolResult::failure(
                'search_unavailable',
                'The product catalogs could not all be checked.',
                [
                    'attempts' => $attempts,
                    'selected_queries' => $selectedQueries,
                    'source_errors' => $sourceErrors,
                    'source_executions' => $sourceExecutions,
                ],
            );
        }

        $limit = min(max(1, (int) ($arguments['limit'] ?? 10)), 100);
        $items = array_slice($items, 0, $limit);
        $outcome = $sourceErrors !== []
            ? 'partial_success'
            : ($items === [] ? 'no_results' : 'catalog_success');

        $this->cycleLogger->event('search_catalog.merge.completed', [
            'source_count' => count($sources),
            'successful_source_count' => count($sourceResults),
            'failed_source_count' => count($sourceErrors),
            'candidate_count' => $mergedCandidateCount,
            'deduplicated_count' => $deduplicatedCount,
            'final_count' => count($items),
            'outcome' => $outcome,
            'result_limit' => $limit,
        ]);

        return ToolResult::success(
            ['ok' => true, 'search' => [
                'dataset' => 'catalogs',
                'count' => count($items),
                'items' => $items,
                'outcome' => $outcome,
                'sources' => $sourceProvenance,
                'execution' => $this->federatedExecution($sourceExecutions, $sourceErrors),
                'pricing' => $pricing,
            ]],
            [
                'card_sources' => $cardSources,
                'source_results' => $sourceResults,
                'source_errors' => $sourceErrors,
                'source_executions' => $sourceExecutions,
                'attempts' => $attempts,
                'selected_queries' => $selectedQueries,
                'pricing' => $pricing,
            ],
        );
    }

    /** @param list<array<string, mixed>> $sources */
    private function federatedPricing(array $sources): array
    {
        $currencies = [];
        $resolvedFields = [];
        foreach ($sources as $source) {
            $currency = $this->sourceCurrency($source);
            if ($currency !== null) {
                $currencies[] = $currency;
            }

            $sourceKey = (string) ($source['type'] ?? 'source').':'.(string) ($source['id'] ?? 'unknown');
            if (($source['type'] ?? null) === 'dataset' && ($source['dataset'] ?? null) instanceof Dataset) {
                $resolvedFields[$sourceKey] = $this->datasetPriceResolution($source['dataset']->fields()->get());
            } elseif (($source['type'] ?? null) === 'api_operation' && ($source['operation'] ?? null) instanceof RuntimeApiOperation) {
                $resolvedFields[$sourceKey] = $this->livePriceFields($source['operation']);
            }
        }

        $currencies = array_values(array_unique($currencies));

        return [
            'semantic_field' => PriceSemanticRole::CurrentPrice->value,
            'resolved_fields' => $resolvedFields,
            'currencies' => $currencies,
            'currency_consistent' => $currencies === [] ? null : ! in_array('mixed', $currencies, true) && count($currencies) <= 1,
            'currency_conversion' => 'not_applied',
        ];
    }

    /** @param array<string, mixed> $arguments */
    private function hasPriceCriteria(array $arguments): bool
    {
        foreach ([...(array) ($arguments['filters'] ?? []), ...(array) ($arguments['sorts'] ?? [])] as $criterion) {
            if (is_array($criterion) && in_array(
                $criterion['field'] ?? null,
                [
                    PriceSemanticRole::CurrentPrice->value,
                    PriceSemanticRole::RegularPrice->value,
                    PriceSemanticRole::DiscountPercent->value,
                ],
                true,
            )) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $source */
    private function sourceCurrency(array $source): ?string
    {
        $currencies = [];
        if (($source['type'] ?? null) === 'dataset' && ($source['dataset'] ?? null) instanceof Dataset) {
            foreach ($source['dataset']->fields()->get() as $field) {
                $role = PriceSemanticRole::normalize($field->semantic_type, $field->key);
                if (! in_array($role, [PriceSemanticRole::CurrentPrice, PriceSemanticRole::RegularPrice], true)) {
                    continue;
                }

                $currency = is_array($field->config) ? $field->config['currency'] ?? null : null;
                if (is_string($currency) && trim($currency) !== '') {
                    $currencies[] = strtoupper(trim($currency));
                }
            }
        }

        if (($source['type'] ?? null) === 'api_operation' && ($source['operation'] ?? null) instanceof RuntimeApiOperation) {
            $mapping = $source['operation']->operation->getAttribute('response_mapping');
            $definitions = is_array($mapping) ? ($mapping['output'] ?? data_get($mapping, 'collection.fields', [])) : [];
            foreach ((array) $definitions as $definition) {
                if (! is_array($definition)) {
                    continue;
                }

                $role = PriceSemanticRole::normalize($definition['semantic_role'] ?? $definition['semantic_type'] ?? null);
                $currency = $definition['currency'] ?? data_get($definition, 'config.currency');
                if (in_array($role, [PriceSemanticRole::CurrentPrice, PriceSemanticRole::RegularPrice], true)
                    && is_string($currency) && trim($currency) !== '') {
                    $currencies[] = strtoupper(trim($currency));
                }
            }
        }

        $currencies = array_values(array_unique($currencies));

        return count($currencies) === 1 ? $currencies[0] : (count($currencies) > 1 ? 'mixed' : null);
    }

    /**
     * @param  list<array<string, mixed>>  $sourceExecutions
     * @param  list<array<string, mixed>>  $sourceErrors
     * @return array<string, mixed>
     */
    private function federatedExecution(array $sourceExecutions, array $sourceErrors): array
    {
        $executions = array_values(array_filter(
            array_map(
                static fn (array $source): mixed => is_array($source['execution'] ?? null)
                    ? $source['execution']
                    : null,
                $sourceExecutions,
            ),
            'is_array',
        ));
        $complete = $sourceErrors === [] && $executions !== [] && ! in_array(
            false,
            array_map(static fn (array $execution): bool => ($execution['complete'] ?? false) === true, $executions),
            true,
        );
        $filtersComplete = $sourceErrors === [] && $executions !== [] && ! in_array(
            false,
            array_map(static fn (array $execution): bool => ($execution['filters_complete'] ?? false) === true, $executions),
            true,
        );
        $sortComplete = $sourceErrors === [] && $executions !== [] && ! in_array(
            false,
            array_map(static fn (array $execution): bool => ($execution['sort_complete'] ?? false) === true, $executions),
            true,
        );
        $moreAvailable = $sourceErrors !== [] || in_array(
            true,
            array_map(static fn (array $execution): bool => ($execution['more_available'] ?? false) === true, $executions),
            true,
        );
        $sortModes = array_values(array_filter(array_map(
            static fn (array $execution): mixed => data_get($execution, 'sort.mode'),
            $executions,
        ), 'is_string'));
        $hasLocalSort = in_array('local_bounded', $sortModes, true) || in_array('complete_local', $sortModes, true);
        $sortMode = $hasLocalSort
            ? ($complete ? 'complete_local' : 'local_bounded')
            : (in_array('remote_bounded', $sortModes, true)
                ? 'remote_bounded'
                : (in_array('remote_guaranteed', $sortModes, true) ? 'remote_guaranteed' : 'none'));
        $globalSortGuaranteed = $sourceErrors === [] && $executions !== [] && ! in_array(
            false,
            array_map(static fn (array $execution): bool => data_get($execution, 'sort.global_guaranteed') === true, $executions),
            true,
        );
        $filters = [
            'remote' => [],
            'local' => [],
            'unsupported' => [],
            'indexed' => [],
        ];
        $constraints = [
            'remote' => [],
            'local' => [],
            'unsupported' => [],
        ];

        foreach ($executions as $execution) {
            foreach (array_keys($filters) as $bucket) {
                $filters[$bucket] = [
                    ...$filters[$bucket],
                    ...array_values(array_filter((array) data_get($execution, "filters.{$bucket}"), 'is_array')),
                ];

                if (! array_key_exists($bucket, $constraints)) {
                    continue;
                }

                $constraints[$bucket] = [
                    ...$constraints[$bucket],
                    ...array_values(array_filter((array) data_get($execution, "constraints.{$bucket}"), 'is_array')),
                ];
            }
        }

        return [
            'filters' => $filters,
            'constraints' => $constraints,
            'sort' => [
                'mode' => $sortMode,
                'global_guaranteed' => $globalSortGuaranteed,
            ],
            'complete' => $complete,
            'filters_complete' => $filtersComplete,
            'sort_complete' => $sortComplete,
            'more_available' => $moreAvailable,
        ];
    }

    /**
     * Rank the already filtered records from all sources using the requested
     * semantic sort. Source-level execution metadata still determines whether
     * the ranking is globally guaranteed.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  list<array<string, mixed>>  $sorts
     * @return list<array<string, mixed>>
     */
    private function sortFederatedItems(array $items, array $sorts): array
    {
        if ($sorts === []) {
            return $items;
        }

        $typedSorts = [];
        foreach ($sorts as $sort) {
            $field = (string) ($sort['field'] ?? '');
            $direction = strtolower((string) ($sort['direction'] ?? 'asc'));
            if ($field === '' || ! in_array($direction, ['asc', 'desc'], true)) {
                continue;
            }

            $type = 'string';
            foreach ($items as $item) {
                $value = $item[$field] ?? null;
                if (is_bool($value)) {
                    $type = 'boolean';

                    break;
                }
                if (is_int($value) || is_float($value) || is_string($value) && is_numeric($value)) {
                    $type = 'decimal';

                    break;
                }
            }
            $typedSorts[] = ['field' => $field, 'direction' => $direction, 'type' => $type];
        }

        return $typedSorts === [] ? $items : $this->recordMatcher->sort($items, $typedSorts);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function deduplicateItems(array $items): array
    {
        $seen = [];
        $result = [];

        foreach ($items as $item) {
            $explicitIdentity = $item['external_id'] ?? $item['product_reference'] ?? null;
            $sourceIdentity = (string) ($item['_source_identity'] ?? 'source:unknown');
            $identity = $explicitIdentity ?? $item['id'] ?? $item['title'] ?? null;
            $key = is_scalar($explicitIdentity)
                ? 'identity:'.(string) $explicitIdentity
                : (is_scalar($identity)
                    ? $sourceIdentity.'|'.(string) $identity
                    : $sourceIdentity.'|'.sha1(serialize($item)));

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = $item;
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchItems(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_array($item)));
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array{type: mixed, id: mixed, name: mixed, slug: mixed, mode: mixed}
     */
    private function sourceLog(array $source): array
    {
        return [
            'type' => $source['type'] ?? null,
            'id' => $source['id'] ?? null,
            'name' => $source['name'] ?? null,
            'slug' => $source['slug'] ?? null,
            'mode' => $source['mode'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $source
     * @param  list<array{type: string, text: string|null, reason: string}>  $searchCandidates
     */
    private function executeLive(Bot $bot, array $arguments, array $searchCandidates, array $source): ToolResult
    {
        $operation = $this->operationResolver->resolveRead($bot, 'search_catalog');

        if ($operation === null) {
            return ToolResult::failure('search_unavailable', 'The catalog search is temporarily unavailable.');
        }

        $mappingValue = $operation->operation->getAttribute('request_mapping');
        $mapping = is_array($mappingValue) ? $mappingValue : [];
        $liveMapping = is_array($mapping['live_query'] ?? null) ? $mapping['live_query'] : [];
        $attempts = [];
        $selectedCandidate = $searchCandidates[0] ?? ['type' => 'browse', 'text' => null, 'reason' => 'browse_all_or_empty_search_text'];

        foreach ($searchCandidates as $attemptIndex => $candidate) {
            $attemptArguments = [
                ...$arguments,
                'text' => $candidate['text'],
                'constraints' => $candidate['type'] === 'literal' ? [] : ($arguments['constraints'] ?? []),
            ];
            $query = LiveReadQuery::fromArguments($attemptArguments);
            $runtimeArguments = $this->liveArguments($operation->operation, $attemptArguments);
            $plan = $this->liveReadPlanner->plan($operation->operation, $query, $runtimeArguments);
            $this->cycleLogger->event('search_catalog.price_semantics.resolved', [
                'source' => $this->sourceLog($source),
                'requested_fields' => $this->liveRequestedPriceFields($query),
                'resolved_fields' => $this->livePriceResolution($query, $operation),
            ]);
            $remoteParameter = $plan->remoteSearchParameter
                ?? (is_string($liveMapping['search_text'] ?? null) ? $liveMapping['search_text'] : null);

            $this->cycleLogger->event('live_read.plan.created', [
                'bot_id' => $bot->id,
                'team_id' => $bot->team_id,
                'operation_id' => $operation->operation->id,
                'operation' => $operation->operation->key,
                'source_id' => $operation->dataSource->id,
                'normalized_search_text' => $query->searchText,
                'configured_remote_search_parameter' => $liveMapping['search_text'] ?? null,
                'remote_search_parameter' => $remoteParameter,
                'runtime_arguments' => $runtimeArguments,
                'remote_arguments' => $plan->remoteArguments,
                'local_search_text' => $plan->localSearchText,
                'requested_minimum' => $plan->requestedMinimum,
                'effective_result_limit' => $plan->effectiveResultLimit,
                'candidate_budget' => $plan->candidateBudget,
                'page_budget' => $plan->pageBudget,
            ]);

            $this->cycleLogger->event('search_catalog.source.plan', [
                'source' => $this->sourceLog($source),
                'requested_filters' => $query->filters,
                'requested_constraints' => $query->constraints,
                'requested_sorts' => $query->sorts,
                'remote_filters' => $plan->remoteFilters,
                'local_filters' => $plan->localFilters,
                'remote_constraints' => $plan->remoteConstraints,
                'local_constraints' => $plan->localConstraints,
                'unsupported_filters' => $plan->unsupportedFilters,
                'unsupported_constraints' => $plan->unsupportedConstraints,
                'unsupported_sorts' => $plan->unsupportedSorts,
                'remote_sorts' => $plan->remoteSorts,
                'local_sorts' => $plan->localSorts,
            ]);

            if ($plan->unsupportedFilters !== []
                || $plan->unsupportedConstraints !== []
                || $plan->unsupportedSorts !== []) {
                return ToolResult::failure(
                    'unsupported_search_criteria',
                    'The catalog cannot verify one or more requested search criteria.',
                    [
                        'attempts' => $attempts,
                        'selected_query' => $candidate['text'],
                        'unsupported_filters' => $plan->unsupportedFilters,
                        'unsupported_constraints' => $plan->unsupportedConstraints,
                        'unsupported_sorts' => $plan->unsupportedSorts,
                        'live_read' => [
                            'remote_filters' => $plan->remoteFilters,
                            'local_filters' => $plan->localFilters,
                            'unsupported_filters' => $plan->unsupportedFilters,
                            'unsupported_constraints' => $plan->unsupportedConstraints,
                            'unsupported_sorts' => $plan->unsupportedSorts,
                        ],
                    ],
                );
            }

            $result = $this->operationExecutor->executeLiveRead($operation, $plan);
            $hasNextCandidate = isset($searchCandidates[$attemptIndex + 1]);

            if (! $result->success) {
                $attempt = [
                    'type' => $candidate['type'],
                    'text' => $candidate['text'],
                    'count' => null,
                    'confirmed_empty' => false,
                    'http_status' => $result->httpStatus,
                    'fallback_triggered' => false,
                ];
                $attempts[] = $attempt;
                $this->logSearchAttempt($source, $attemptIndex + 1, $candidate, null, false, $result->httpStatus, false, $remoteParameter);

                return ToolResult::failure(
                    $result->error ?? 'search_unavailable',
                    $result->message ?? 'The catalog search is temporarily unavailable.',
                    [
                        'attempts' => $attempts,
                        'selected_query' => $candidate['text'],
                        'live_read' => ['attempts' => $attempts],
                    ],
                );
            }

            $items = $this->normalizeLiveItems((array) ($result->data['records'] ?? []), $operation);
            $liveReadMeta = (array) ($result->data['meta'] ?? []);
            $liveReadMeta['remote_constraints'] = $plan->remoteConstraints;
            $liveReadMeta['local_constraints'] = $plan->localConstraints;
            $liveReadMeta['unsupported_constraints'] = $plan->unsupportedConstraints;
            $liveReadMeta['product_mapped_count'] = count($items);
            $liveReadMeta['pricing'] = [
                'semantic_field' => PriceSemanticRole::CurrentPrice->value,
                'resolved_fields' => $this->livePriceResolution($query, $operation),
                'currency_consistent' => ($currency = $this->sourceCurrency($source)) === null ? null : $currency !== 'mixed',
                'currency_conversion' => 'not_applied',
            ];
            if (count($items) === 0 && ($liveReadMeta['complete'] ?? false) !== true) {
                return ToolResult::failure(
                    'search_incomplete',
                    'The catalog search could not be completed within its safety limits.',
                    [
                        'attempts' => $attempts,
                        'selected_query' => $candidate['text'],
                        'live_read' => $liveReadMeta,
                    ],
                );
            }
            $confirmedEmpty = count($items) === 0 && ($liveReadMeta['confirmed_empty'] ?? false) === true;
            $fallbackTriggered = $confirmedEmpty && $hasNextCandidate;
            $attempt = [
                'type' => $candidate['type'],
                'text' => $candidate['text'],
                'count' => count($items),
                'confirmed_empty' => $confirmedEmpty,
                'http_status' => $result->httpStatus,
                'fallback_triggered' => $fallbackTriggered,
            ];
            $attempts[] = $attempt;
            $this->logSearchAttempt($source, $attemptIndex + 1, $candidate, count($items), $confirmedEmpty, $result->httpStatus, $fallbackTriggered, $remoteParameter);
            $selectedCandidate = $candidate;

            $this->cycleLogger->event('search_catalog.result.mapped', [
                'bot_id' => $bot->id,
                'team_id' => $bot->team_id,
                'operation_id' => $operation->operation->id,
                'operation' => $operation->operation->key,
                'normalized_search_text' => $query->searchText,
                'product_mapped_count' => count($items),
                'live_read_meta' => $liveReadMeta,
            ]);

            if (! $fallbackTriggered) {
                $liveReadMeta['attempts'] = $attempts;
                $liveReadMeta['selected_query'] = $selectedCandidate['text'];

                return ToolResult::success(
                    ['ok' => true, 'search' => [
                        'dataset' => 'live',
                        'count' => count($items),
                        'items' => $items,
                        'outcome' => count($items) > 0 ? 'catalog_success' : 'no_results',
                        'execution' => $liveReadMeta['execution'] ?? [],
                        'sources' => [[
                            ...$this->sourceLog($source),
                            'count' => count($items),
                        ]],
                    ]],
                    [
                        'attempts' => $attempts,
                        'selected_query' => $selectedCandidate['text'],
                        'card_source' => ['live_items' => $items],
                        'live_read' => $liveReadMeta,
                    ],
                );
            }
        }

        return ToolResult::failure('search_unavailable', 'The catalog search is temporarily unavailable.');
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function liveArguments(ApiOperation $operation, array $arguments): array
    {
        $schemaValue = $operation->getAttribute('request_schema');
        $schema = is_array($schemaValue) ? $schemaValue : [];
        $propertiesValue = $schema['properties'] ?? [];
        $properties = is_array($propertiesValue) ? $propertiesValue : [];
        $mappingValue = $operation->getAttribute('request_mapping');
        $mapping = is_array($mappingValue) ? $mappingValue : [];
        $queryMapping = is_array($mapping['query'] ?? null) ? $mapping['query'] : [];
        $bodyMapping = is_array($mapping['body'] ?? null) ? $mapping['body'] : [];
        $mapped = [...$queryMapping, ...$bodyMapping];
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

    private function searchText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = trim($value);

        return $text !== '' ? $text : null;
    }

    /**
     * @return list<array{type: string, text: string|null, reason: string}>
     */
    private function searchCandidates(
        ?string $literalSearchText,
        ?string $originalSearchText,
        ?string $canonicalSearchText,
        ?string $relaxedSearchText,
    ): array {
        $candidates = [];

        if ($literalSearchText !== null) {
            $candidates[] = [
                'type' => 'literal',
                'text' => $literalSearchText,
                'reason' => 'title_like_or_identifier_query',
            ];
        } elseif ($originalSearchText !== null) {
            $candidates[] = [
                'type' => 'original',
                'text' => $originalSearchText,
                'reason' => 'customer_original_catalog_term',
            ];
        }

        if ($canonicalSearchText !== null
            && ! $this->equivalentSearchText($literalSearchText ?? $originalSearchText, $canonicalSearchText)) {
            $candidates[] = [
                'type' => 'canonical_fallback',
                'text' => $canonicalSearchText,
                'reason' => 'canonical_model_or_catalog_term',
            ];
        }

        $relaxedAlreadyPresent = false;
        foreach ($candidates as $candidate) {
            if ($this->equivalentSearchText($candidate['text'], $relaxedSearchText)) {
                $relaxedAlreadyPresent = true;

                break;
            }
        }

        if ($relaxedSearchText !== null && ! $relaxedAlreadyPresent) {
            $candidates[] = [
                'type' => 'relaxed_core',
                'text' => $relaxedSearchText,
                'reason' => 'relaxed_core_entity',
            ];
        }

        return $candidates !== []
            ? array_slice($candidates, 0, 3)
            : [['type' => 'browse', 'text' => null, 'reason' => 'browse_all_or_empty_search_text']];
    }

    private function relaxedSearchText(?string $canonicalSearchText): ?string
    {
        if ($canonicalSearchText === null) {
            return null;
        }

        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}_.-]*/u', $canonicalSearchText, $matches);
        $tokens = $matches[0];

        if (count($tokens) !== 2 || preg_match('/\d/u', (string) $tokens[1]) === 1) {
            return null;
        }

        return (string) $tokens[1];
    }

    private function equivalentSearchText(?string $left, ?string $right): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }

        if (trim($left) === trim($right)) {
            return true;
        }

        if ($this->looksLikeExactIdentifier($left) || $this->looksLikeExactIdentifier($right)) {
            return false;
        }

        return mb_strtolower(trim($left)) === mb_strtolower(trim($right));
    }

    private function looksLikeExactIdentifier(string $value): bool
    {
        return preg_match('/^(?=.*\d)[A-Za-z0-9][A-Za-z0-9._-]*$/', trim($value)) === 1;
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array{type: string, text: string|null, reason: string}  $candidate
     */
    private function logSearchAttempt(
        array $source,
        int $attemptNumber,
        array $candidate,
        ?int $resultCount,
        bool $confirmedEmpty,
        ?int $httpStatus,
        bool $fallbackTriggered,
        ?string $remoteParameter = null,
    ): void {
        $this->cycleLogger->event('search_catalog.attempt', [
            'source' => $this->sourceLog($source),
            'source_mode' => $source['mode'] ?? null,
            'attempt_number' => $attemptNumber,
            'attempt_type' => $candidate['type'],
            'candidate_reason' => $candidate['reason'],
            'query_text' => $candidate['text'],
            'remote_parameter' => $remoteParameter,
            'result_count' => $resultCount,
            'confirmed_empty' => $confirmedEmpty,
            'http_status' => $httpStatus,
            'fallback_triggered' => $fallbackTriggered,
        ]);
    }

    /**
     * @param  array<int|string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function normalizeLiveItems(array $data, RuntimeApiOperation $operation): array
    {
        $items = array_is_list($data) ? $data : [];

        if ($items === []) {
            foreach (['items', 'products', 'results', 'data'] as $key) {
                $candidate = $data[$key] ?? null;

                if (is_array($candidate) && array_is_list($candidate)) {
                    $items = $candidate;

                    break;
                }
            }
        }

        if ($items === [] && ! array_is_list($data)) {
            $items = [$data];
        }

        $priceFields = $this->livePriceFields($operation);

        return array_values(array_filter(array_map(function (mixed $item) use ($priceFields): ?array {
            if (! is_array($item)) {
                return null;
            }

            $item = $this->addSemanticPriceValues($item, $priceFields);

            $mapped = [
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
            foreach (array_keys($priceFields) as $role) {
                if (array_key_exists($role, $item)) {
                    $mapped[$role] = $item[$role];
                }
            }

            foreach (['external_id', 'product_reference'] as $identityField) {
                if (is_scalar($item[$identityField] ?? null)) {
                    $mapped[$identityField] = (string) $item[$identityField];
                }
            }

            return $mapped;
        }, $items), static fn (?array $item): bool => is_array($item) && is_scalar($item['title'] ?? null)));
    }

    /** @return list<array<string, mixed>> */
    private function normalizeFilterValues(mixed $filters): array
    {
        return array_values(array_map(
            static function (mixed $filter): array {
                if (! is_array($filter) || ($filter['operator'] ?? null) !== 'between' || ($filter['value'] ?? null) !== null) {
                    return is_array($filter) ? $filter : [];
                }

                if (array_key_exists('minimum', $filter) && array_key_exists('maximum', $filter)) {
                    $filter['value'] = [$filter['minimum'], $filter['maximum']];
                }

                return $filter;
            },
            array_values(array_filter((array) $filters, 'is_array')),
        ));
    }

    /** @return array<string, string> */
    private function livePriceFields(RuntimeApiOperation $operation): array
    {
        $roles = [];
        foreach ($this->liveReadPlanner->fields($operation->operation) as $field => $definition) {
            if (! is_array($definition) || ! is_string($definition['semantic_role'] ?? null)) {
                continue;
            }

            $roles[$definition['semantic_role']] = (string) ($definition['resolved_field'] ?? $field);
        }

        return $roles;
    }

    /** @param Collection<int|string, DatasetField> $fields */
    private function datasetPriceResolution(Collection $fields): array
    {
        $resolution = [];
        foreach ($fields as $field) {
            $role = PriceSemanticRole::normalize($field->semantic_type, $field->key);
            if ($role instanceof PriceSemanticRole) {
                $resolution[$role->value] = $field->key;
            }
        }

        return $resolution;
    }

    /** @return array<string, string> */
    private function livePriceResolution(LiveReadQuery $query, RuntimeApiOperation $operation): array
    {
        $fields = $this->liveReadPlanner->fields($operation->operation);
        $resolution = [];
        foreach ([...$query->filters, ...$query->sorts] as $request) {
            $field = (string) ($request['field'] ?? '');
            $definition = $fields[$field] ?? null;
            if (! is_array($definition) || ! is_string($definition['semantic_role'] ?? null)) {
                continue;
            }

            $resolution[$definition['semantic_role']] = (string) ($definition['resolved_field'] ?? $field);
        }

        return $resolution;
    }

    /** @return list<string> */
    private function liveRequestedPriceFields(LiveReadQuery $query): array
    {
        $requested = [];
        foreach ([...$query->filters, ...$query->sorts] as $request) {
            $role = PriceSemanticRole::tryFrom(strtolower(trim((string) ($request['field'] ?? ''))));

            if ($role instanceof PriceSemanticRole) {
                $requested[] = $role->value;
            }
        }

        return array_values(array_unique($requested));
    }

    /** @param array<string, string> $priceFields */
    private function normalizeSourceItems(array $items, array $source): array
    {
        $priceFields = [];
        if (($source['type'] ?? null) === 'dataset' && ($source['dataset'] ?? null) instanceof Dataset) {
            foreach (($source['dataset']->fields()->get()) as $field) {
                $role = PriceSemanticRole::normalize($field->semantic_type, $field->key);
                if ($role instanceof PriceSemanticRole) {
                    $priceFields[$role->value] = $field->key;
                }
            }
        }

        return array_map(
            fn (array $item): array => $this->addSemanticPriceValues($item, $priceFields),
            $items,
        );
    }

    /** @param array<string, string> $priceFields */
    private function addSemanticPriceValues(array $item, array $priceFields): array
    {
        foreach ($priceFields as $role => $field) {
            if (array_key_exists($field, $item)) {
                $item[$role] = $item[$field];
            }
        }

        $current = $item[PriceSemanticRole::CurrentPrice->value] ?? null;
        $regular = $item[PriceSemanticRole::RegularPrice->value] ?? null;
        if (! array_key_exists(PriceSemanticRole::DiscountPercent->value, $item)
            && is_scalar($current) && is_scalar($regular)
            && is_numeric($current) && is_numeric($regular) && (float) $regular > 0) {
            $item[PriceSemanticRole::DiscountPercent->value] = (((float) $regular - (float) $current) / (float) $regular) * 100;
        }

        if (array_key_exists(PriceSemanticRole::CurrentPrice->value, $item)) {
            $item['price'] = $item[PriceSemanticRole::CurrentPrice->value];
        }
        if (array_key_exists(PriceSemanticRole::RegularPrice->value, $item)) {
            $item['old_price'] = $item[PriceSemanticRole::RegularPrice->value];
        }
        if (array_key_exists(PriceSemanticRole::DiscountPercent->value, $item)) {
            $item['discount'] = $item[PriceSemanticRole::DiscountPercent->value];
        }

        return $item;
    }

    /** @return array<string, mixed> */
    private function datasetRecordValues(DatasetRecord $record): array
    {
        $payload = [];
        $recordPayload = $record->getAttribute('payload');
        foreach (is_array($recordPayload) ? $recordPayload : [] as $key => $value) {
            if (is_string($key)) {
                $payload[$key] = $value;
            }
        }

        return [...$payload, 'searchable_text' => (string) $record->searchable_text];
    }
}
