<?php

namespace App\Services\Knowledge;

use App\Models\Dataset;
use App\Models\DatasetRecord;
use App\Services\Search\Contracts\SearchEngine;
use App\Services\Search\Data\SearchQuery;
use App\Services\Search\Data\SearchResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class KnowledgeSearchService
{
    public function __construct(
        private readonly KnowledgeQueryNormalizer $normalizer,
        private readonly SearchEngine $searchEngine,
    ) {}

    public function search(Dataset $dataset, string $query, int $limit): SearchResult
    {
        $variants = $this->normalizer->variants($query);

        if ($variants === []) {
            return new SearchResult(records: [], total: 0);
        }

        $primaryRecords = $this->candidateRecords($dataset, $variants);
        $backendRecords = $this->backendCandidates($dataset, $query);
        $records = $primaryRecords->concat($backendRecords)->unique('id')->values();
        $ranked = $this->rank(
            $records->all(),
            $variants,
            (float) config('search.knowledge.primary_minimum_confidence', 0.52),
        );

        if ($ranked !== []) {
            return new SearchResult(
                records: array_slice($ranked, 0, $limit),
                total: count($ranked),
            );
        }

        $fallbackRecords = $this->fallbackCandidates($dataset, $variants);
        $fallbackRanked = $this->rank(
            $fallbackRecords->all(),
            $variants,
            (float) config('search.knowledge.fallback_minimum_confidence', 0.58),
            true,
        );

        return new SearchResult(
            records: array_slice($fallbackRanked, 0, $limit),
            total: count($fallbackRanked),
        );
    }

    /**
     * @param  array<string, list<string>>  $variants
     * @return Collection<int, DatasetRecord>
     */
    private function candidateRecords(Dataset $dataset, array $variants): Collection
    {
        $terms = collect($variants)->flatten()->unique()->filter(
            fn (string $variant): bool => mb_strlen($variant, 'UTF-8') >= 3,
        )->values();

        return DatasetRecord::query()
            ->active()
            ->where('dataset_id', $dataset->id)
            ->where(function (Builder $builder) use ($terms): void {
                foreach ($terms as $term) {
                    $builder->orWhere('searchable_text', 'ILIKE', '%'.$this->escapeLikePattern($term).'%');
                }
            })
            ->latest('id')
            ->limit((int) config('search.knowledge.candidate_limit', 250))
            ->get();
    }

    /**
     * @param  array<string, list<string>>  $variants
     * @return Collection<int, DatasetRecord>
     */
    private function fallbackCandidates(Dataset $dataset, array $variants): Collection
    {
        $prefixes = collect($variants)->flatten()->map(
            fn (string $variant): string => mb_substr($variant, 0, max(3, mb_strlen($variant, 'UTF-8') - 2), 'UTF-8'),
        )->unique()->values();

        return DatasetRecord::query()
            ->active()
            ->where('dataset_id', $dataset->id)
            ->where(function (Builder $builder) use ($prefixes): void {
                foreach ($prefixes as $prefix) {
                    $builder->orWhere('searchable_text', 'ILIKE', '%'.$this->escapeLikePattern($prefix).'%');
                }
            })
            ->latest('id')
            ->limit((int) config('search.knowledge.candidate_limit', 250))
            ->get();
    }

    /**
     * Keep the configured backend useful for exact/Typesense-specific behavior,
     * while PostgreSQL remains the authoritative broad candidate source.
     *
     * @return Collection<int, DatasetRecord>
     */
    private function backendCandidates(Dataset $dataset, string $query): Collection
    {
        try {
            $result = $this->searchEngine->search(new SearchQuery(
                datasetId: $dataset->id,
                text: $this->normalizer->normalize($query),
                limit: min((int) config('search.knowledge.candidate_limit', 250), 100),
            ));

            return new Collection($result->records);
        } catch (\Throwable) {
            return new Collection;
        }
    }

    /**
     * @param  list<DatasetRecord>  $records
     * @param  array<string, list<string>>  $variants
     * @return list<DatasetRecord>
     */
    private function rank(array $records, array $variants, float $minimumConfidence, bool $fuzzy = false): array
    {
        $ranked = [];

        foreach ($records as $record) {
            $score = $this->score($record, $variants, $fuzzy);

            if ($score['confidence'] < $minimumConfidence) {
                continue;
            }

            $ranked[] = [
                'record' => $record,
                'confidence' => $score['confidence'],
                'coverage' => $score['coverage'],
                'exact_title' => $score['exact_title'],
            ];
        }

        usort($ranked, fn (array $left, array $right): int => [$right['exact_title'], $right['confidence'], $right['coverage'], $right['record']->id]
                <=> [$left['exact_title'], $left['confidence'], $left['coverage'], $left['record']->id]
        );

        return array_values(array_map(
            fn (array $match): DatasetRecord => $match['record'],
            $ranked,
        ));
    }

    /**
     * @param  array<string, list<string>>  $variants
     * @return array{confidence: float, coverage: float, exact_title: bool}
     */
    private function score(DatasetRecord $record, array $variants, bool $fuzzy): array
    {
        $payload = $record->getAttribute('payload');
        $payload = is_array($payload) ? $payload : [];
        $matchedTokens = 0;
        $weightedMatches = 0.0;
        $exactTitle = false;
        $tokenCount = count($variants);

        foreach ($variants as $token => $tokenVariants) {
            $bestWeight = 0.0;
            foreach ($this->fieldTexts($payload, $record) as $field => $text) {
                $fieldTokens = $this->normalizer->tokens($text);
                $weight = $this->fieldWeight($field);
                $matchType = $this->matchType($tokenVariants, $fieldTokens, $fuzzy);

                if ($matchType === 0) {
                    continue;
                }

                $bestWeight = max($bestWeight, $weight * ($matchType === 1 ? 0.75 : 1.0));

                if ($field === 'title' && $matchType === 2) {
                    $exactTitle = true;
                }
            }

            if ($bestWeight === 0.0) {
                continue;
            }

            $matchedTokens++;
            $weightedMatches += $bestWeight;
        }

        $coverage = $tokenCount === 0 ? 0.0 : $matchedTokens / $tokenCount;
        $fieldQuality = $tokenCount === 0 ? 0.0 : min(1.0, $weightedMatches / ($tokenCount * 4));
        $confidence = min(1.0, ($coverage * 0.65) + ($fieldQuality * 0.35) + ($exactTitle ? 0.1 : 0.0));

        return [
            'confidence' => $confidence,
            'coverage' => $coverage,
            'exact_title' => $exactTitle,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    private function fieldTexts(array $payload, DatasetRecord $record): array
    {
        $texts = [];

        foreach (['title', 'question', 'category', 'tags', 'content', 'answer'] as $field) {
            if (isset($payload[$field]) && is_scalar($payload[$field])) {
                $texts[$this->fieldAlias($field)] = (string) $payload[$field];
            }
        }

        $searchableText = $record->getAttribute('searchable_text');

        if (is_string($searchableText) && $searchableText !== '') {
            $texts['searchable_text'] = $searchableText;
        }

        return $texts;
    }

    private function fieldAlias(string $field): string
    {
        return match ($field) {
            'question' => 'title',
            'answer' => 'content',
            default => $field,
        };
    }

    private function fieldWeight(string $field): float
    {
        return match ($field) {
            'title' => 4.0,
            'category', 'tags' => 3.0,
            'content' => 1.0,
            default => 0.5,
        };
    }

    /**
     * 2 = exact/variant match, 1 = bounded fuzzy/prefix match, 0 = no match.
     *
     * @param  list<string>  $variants
     * @param  list<string>  $fieldTokens
     */
    private function matchType(array $variants, array $fieldTokens, bool $fuzzy): int
    {
        foreach ($variants as $variant) {
            if (in_array($variant, $fieldTokens, true)) {
                return 2;
            }

            foreach ($fieldTokens as $fieldToken) {
                if (mb_strlen($variant, 'UTF-8') >= 4
                    && (str_starts_with($fieldToken, $variant) || str_starts_with($variant, $fieldToken))) {
                    return 2;
                }
            }
        }

        if (! $fuzzy) {
            return 0;
        }

        foreach ($variants as $variant) {
            foreach ($fieldTokens as $fieldToken) {
                if (mb_strlen($variant, 'UTF-8') < 4 || mb_strlen($fieldToken, 'UTF-8') < 4) {
                    continue;
                }

                $distance = $this->unicodeDistance($variant, $fieldToken);
                $length = max(mb_strlen($variant, 'UTF-8'), mb_strlen($fieldToken, 'UTF-8'));

                if ($distance <= 1 || ($distance / $length) <= 0.25) {
                    return 1;
                }
            }
        }

        return 0;
    }

    private function unicodeDistance(string $left, string $right): int
    {
        $leftCharacters = preg_split('//u', $left, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $rightCharacters = preg_split('//u', $right, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $previous = range(0, count($rightCharacters));

        foreach ($leftCharacters as $leftIndex => $leftCharacter) {
            $current = [$leftIndex + 1];

            foreach ($rightCharacters as $rightIndex => $rightCharacter) {
                $current[] = $leftCharacter === $rightCharacter
                    ? $previous[$rightIndex]
                    : min($previous[$rightIndex], $current[$rightIndex], $previous[$rightIndex + 1]) + 1;
            }

            $previous = $current;
        }

        return $previous[count($rightCharacters)] ?? 0;
    }

    private function escapeLikePattern(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
