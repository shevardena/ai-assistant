<?php

namespace App\Services\Ai\Tools;

use App\Enums\DatasetStatus;
use App\Models\Bot;
use App\Services\Ai\Formatters\AiSearchResultFormatter;
use App\Services\Ai\Tools\Contracts\BotTool;
use App\Services\Search\Data\SearchQuery;
use App\Services\Search\Exceptions\InvalidSearchCriteriaException;
use App\Services\Search\SearchService;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class RecommendProductsTool implements BotTool
{
    public function __construct(
        private readonly SearchService $searchService,
        private readonly AiSearchResultFormatter $resultFormatter,
    ) {}

    public function name(): string
    {
        return 'recommend_products';
    }

    public function description(): string
    {
        return 'Recommend products from authorized catalog datasets that fit the user preferences.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(Bot $bot): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string'],
                'limit' => [
                    'type' => ['integer', 'null'],
                    'minimum' => 1,
                    'maximum' => 10,
                ],
            ],
            'required' => ['query', 'limit'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(Bot $bot, array $arguments, ToolExecutionContext $context): ToolResult
    {
        $query = $this->query($arguments);
        $limit = $this->limit($arguments);

        if ($query === null
            || $limit === null
            || (int) $context->bot->id !== (int) $bot->id
            || (int) $context->team->id !== (int) $bot->team_id) {
            return $this->invalidRecommendation();
        }

        try {
            $datasets = $bot->datasets()
                ->wherePivot('is_enabled', true)
                ->where('datasets.team_id', $bot->team_id)
                ->catalog()
                ->where('datasets.status', DatasetStatus::Ready->value)
                ->whereHas('fields', fn (Builder $builder): Builder => $builder
                    ->where('is_displayable', true))
                ->with('fields')
                ->orderBy('bot_datasets.priority')
                ->get();

            if ($datasets->isEmpty()) {
                return ToolResult::success([
                    'ok' => true,
                    'recommendations' => [],
                ]);
            }

            $recommendations = [];
            $cardSources = [];

            foreach ($datasets as $dataset) {
                $searchResult = $this->searchService->search(
                    $context->team,
                    new SearchQuery(
                        datasetId: (int) $dataset->id,
                        text: $query,
                        limit: $limit,
                    ),
                );
                $formatted = $this->resultFormatter->format($dataset, $searchResult);
                $remaining = $limit - count($recommendations);
                $items = array_slice($formatted['items'], 0, $remaining);

                if ($items === []) {
                    continue;
                }

                $recommendations = [...$recommendations, ...$items];
                $cardSources[] = [
                    'dataset_id' => (int) $dataset->id,
                    'record_ids' => array_slice(
                        array_map(
                            fn ($record): int => (int) $record->id,
                            $searchResult->records,
                        ),
                        0,
                        count($items),
                    ),
                ];

                if (count($recommendations) >= $limit) {
                    break;
                }
            }

            $metadata = $cardSources === [] ? [] : ['card_sources' => $cardSources];

            if (count($cardSources) === 1) {
                $metadata['card_source'] = $cardSources[0];
            }

            return ToolResult::success(
                data: [
                    'ok' => true,
                    'recommendations' => $recommendations,
                ],
                metadata: $metadata,
            );
        } catch (InvalidSearchCriteriaException) {
            return $this->invalidRecommendation();
        } catch (Throwable $exception) {
            logger()->warning('AI product recommendation failed.', [
                'bot_id' => $bot->id,
                'team_id' => $bot->team_id,
                'tool' => $this->name(),
                'exception' => $exception::class,
            ]);
            report($exception);

            return ToolResult::failure(
                'recommendations_unavailable',
                'Product recommendations are temporarily unavailable.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function query(array $arguments): ?string
    {
        if (array_diff(array_keys($arguments), ['query', 'limit']) !== []
            || ! array_key_exists('query', $arguments)
            || ! is_string($arguments['query'])) {
            return null;
        }

        $query = trim($arguments['query']);

        if ($query === ''
            || mb_strlen($query) > 1000
            || preg_match('/[\x00-\x1F\x7F]/', $query) === 1) {
            return null;
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function limit(array $arguments): ?int
    {
        if (! array_key_exists('limit', $arguments) || $arguments['limit'] === null) {
            return min(max(1, (int) config('openai.max_results', 10)), 10);
        }

        $limit = $arguments['limit'];

        return is_int($limit) && $limit >= 1 && $limit <= 10 ? $limit : null;
    }

    private function invalidRecommendation(): ToolResult
    {
        return ToolResult::failure(
            'invalid_recommendation',
            'The recommendation query must be a non-empty string of 1000 characters or fewer, with a limit between 1 and 10.',
        );
    }
}
