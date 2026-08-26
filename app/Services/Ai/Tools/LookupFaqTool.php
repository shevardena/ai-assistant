<?php

namespace App\Services\Ai\Tools;

use App\Enums\DatasetStatus;
use App\Models\Bot;
use App\Models\Dataset;
use App\Models\DatasetRecord;
use App\Services\Ai\Tools\Contracts\BotTool;
use App\Services\Knowledge\KnowledgeSearchService;
use App\Services\Search\Exceptions\InvalidSearchCriteriaException;
use App\Services\Search\SearchService;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class LookupFaqTool implements BotTool
{
    public function __construct(
        private readonly KnowledgeSearchService $knowledgeSearchService,
    ) {}

    public function name(): string
    {
        return 'lookup_faq';
    }

    public function description(): string
    {
        return 'Search authorized FAQ and knowledge datasets for information relevant to the user question.';
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
            ],
            'required' => ['query'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(Bot $bot, array $arguments, ToolExecutionContext $context): ToolResult
    {
        $query = $this->query($arguments);

        if ($query === null
            || (int) $context->bot->id !== (int) $bot->id
            || (int) $context->team->id !== (int) $bot->team_id) {
            return $this->invalidQuery();
        }

        try {
            $datasets = $bot->datasets()
                ->wherePivot('is_enabled', true)
                ->where('datasets.team_id', $bot->team_id)
                ->knowledge()
                ->where('datasets.status', DatasetStatus::Ready->value)
                ->whereHas('fields', fn (Builder $builder): Builder => $builder
                    ->where('is_displayable', true))
                ->with('fields')
                ->orderBy('bot_datasets.priority')
                ->get();

            if ($datasets->isEmpty()) {
                return ToolResult::success([
                    'ok' => true,
                    'results' => [],
                ]);
            }

            $results = [];
            $limit = min(
                max(1, (int) config('openai.max_results', 10)),
                SearchService::MAX_LIMIT,
            );

            foreach ($datasets as $dataset) {
                $searchResult = $this->knowledgeSearchService->search($dataset, $query, $limit);

                foreach ($searchResult->records as $record) {
                    if (! $record->is_active) {
                        continue;
                    }

                    $results[] = $this->displayableRecord($dataset, $record);

                    if (count($results) >= $limit) {
                        break 2;
                    }
                }
            }

            return ToolResult::success([
                'ok' => true,
                'results' => $results,
            ]);
        } catch (InvalidSearchCriteriaException) {
            return $this->invalidQuery();
        } catch (Throwable $exception) {
            logger()->warning('AI FAQ lookup failed.', [
                'bot_id' => $bot->id,
                'team_id' => $bot->team_id,
                'tool' => $this->name(),
                'exception' => $exception::class,
            ]);
            report($exception);

            return ToolResult::failure(
                'lookup_unavailable',
                'The FAQ lookup is temporarily unavailable.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function query(array $arguments): ?string
    {
        if (array_diff(array_keys($arguments), ['query']) !== []
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
     * @return array<string, mixed>
     */
    private function displayableRecord(Dataset $dataset, DatasetRecord $record): array
    {
        $payload = $record->getAttribute('payload');
        $payload = is_array($payload) ? $payload : [];
        $result = [];

        foreach ($dataset->fields as $field) {
            if (! $field->is_displayable || ! array_key_exists($field->key, $payload)) {
                continue;
            }

            $value = $payload[$field->key];

            if (is_scalar($value) || $value === null) {
                $result[(string) $field->key] = $value;
            }
        }

        if (in_array($dataset->entity_type, ['knowledge', 'faq'], true)) {
            return [
                'title' => $result['title'] ?? null,
                'content' => $result['content'] ?? null,
                'category' => $result['category'] ?? null,
                'source_url' => $result['source_url'] ?? null,
                'language' => $result['language'] ?? null,
            ];
        }

        return $result;
    }

    private function invalidQuery(): ToolResult
    {
        return ToolResult::failure(
            'invalid_query',
            'The FAQ query must be a non-empty string of 1000 characters or fewer.',
        );
    }
}
