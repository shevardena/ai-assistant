<?php

namespace App\Services\Ai\Formatters;

use App\Enums\PriceSemanticRole;
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
        $priceFields = [];
        foreach ($fields as $field) {
            $role = PriceSemanticRole::normalize($field->semantic_type, $field->key);
            if ($role instanceof PriceSemanticRole && ! isset($priceFields[$role->value])) {
                $priceFields[$role->value] = $field->key;
            }
        }

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

            foreach ($priceFields as $role => $key) {
                if (array_key_exists($key, $payload)) {
                    $item[$role] = $payload[$key];
                }
            }

            if (! array_key_exists(PriceSemanticRole::CurrentPrice->value, $item)
                && array_key_exists(PriceSemanticRole::RegularPrice->value, $item)) {
                $item[PriceSemanticRole::CurrentPrice->value] = $item[PriceSemanticRole::RegularPrice->value];
            }

            $current = $item[PriceSemanticRole::CurrentPrice->value] ?? null;
            $regular = $item[PriceSemanticRole::RegularPrice->value] ?? null;
            if (! array_key_exists(PriceSemanticRole::DiscountPercent->value, $item)
                && is_scalar($current) && is_scalar($regular)
                && is_numeric($current) && is_numeric($regular) && (float) $regular > 0) {
                $item[PriceSemanticRole::DiscountPercent->value] = (((float) $regular - (float) $current) / (float) $regular) * 100;
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
