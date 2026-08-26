<?php

namespace App\Services\Ai\Formatters;

use App\Models\Dataset;
use App\Services\Search\Data\SearchResult;

class AiSearchResultFormatter
{
    /**
     * @return array{dataset: string, count: int, items: list<array<string, mixed>>}
     */
    public function format(Dataset $dataset, SearchResult $result): array
    {
        $fields = $dataset->fields()->get();
        $displayableKeys = $fields
            ->filter(fn ($field): bool => (bool) $field->is_displayable)
            ->pluck('key')
            ->values()
            ->all();

        $items = [];

        foreach ($result->records as $record) {
            $payload = $record->getAttribute('payload');
            $payload = is_array($payload) ? $payload : [];
            $reference = (string) $record->getAttribute('external_id');
            $item = [
                'external_id' => $reference,
                'product_reference' => $reference,
            ];

            foreach ($displayableKeys as $key) {
                if (array_key_exists($key, $payload)) {
                    $item[$key] = $payload[$key];
                }
            }

            $items[] = $item;
        }

        return [
            'dataset' => (string) $dataset->slug,
            'count' => $result->total,
            'items' => $items,
        ];
    }
}
