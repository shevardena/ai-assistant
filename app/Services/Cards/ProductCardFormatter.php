<?php

namespace App\Services\Cards;

use App\Models\Bot;
use App\Models\BotCardTemplate;
use App\Models\Dataset;
use App\Models\DatasetField;
use App\Models\DatasetRecord;
use App\Services\Conversations\ConversationCycleLogger;
use Illuminate\Support\Collection;

class ProductCardFormatter
{
    public function __construct(private readonly ?ConversationCycleLogger $cycleLogger = null) {}

    /**
     * Format one record using a validated, dataset-specific card template.
     */
    public function format(Bot $bot, Dataset $dataset, DatasetRecord $record): ?ProductCard
    {
        if ((int) $bot->team_id !== (int) $dataset->team_id
            || (int) $record->dataset_id !== (int) $dataset->id) {
            return null;
        }

        $template = $this->template($bot, $dataset);

        if (! $template instanceof BotCardTemplate) {
            return null;
        }

        $fields = $this->displayableFields($dataset);
        $rawPayload = $record->getAttribute('payload');
        $payload = is_array($rawPayload) ? $rawPayload : [];
        $values = $this->mappedValues($template, $fields, $payload);

        $title = $this->stringValue($values->get('title'));

        if ($title === null) {
            return null;
        }

        $price = $this->primitiveValue($values->get('price'));
        $oldPrice = $this->primitiveValue($values->get('old_price'));
        $buttonLabel = $this->stringValue(data_get($template->getAttribute('layout'), 'button_label'))
            ?? $this->stringValue($values->get('button_label'));

        if (! is_numeric($price) || ! is_numeric($oldPrice) || (float) $oldPrice <= (float) $price) {
            $oldPrice = null;
        }

        return new ProductCard(
            id: (string) $record->external_id,
            image: $this->safeUrl($values->get('image')),
            title: $title,
            subtitle: $this->stringValue($values->get('subtitle')),
            description: $this->stringValue($values->get('description')),
            price: $price,
            oldPrice: $oldPrice,
            discount: $this->primitiveValue($values->get('discount')),
            url: $this->safeUrl($values->get('url')),
            buttonLabel: $buttonLabel,
            styles: $this->styles($template),
        );
    }

    /**
     * Format records returned by successful searches, preserving encounter order.
     *
     * @param  list<array<string, mixed>>  $sources
     * @return list<array<string, int|float|string|null>>
     */
    public function formatSearchSources(Bot $bot, array $sources): array
    {
        $candidateCount = 0;
        $cards = [];
        $seen = [];
        $maximum = max(1, (int) config('widget.max_result_cards', 6));

        foreach ($sources as $source) {
            $candidateCount += is_array($source['live_items'] ?? null)
                ? count($source['live_items'])
                : count((array) ($source['record_ids'] ?? []));

            if (count($cards) >= $maximum) {
                break;
            }

            if (is_array($source['live_items'] ?? null)) {
                foreach (array_slice($source['live_items'], 0, $maximum - count($cards)) as $item) {
                    if (! is_array($item)) {
                        continue;
                    }

                    $card = $this->formatLiveItem($bot, $item);

                    if ($card instanceof ProductCard) {
                        $cards[] = $card->toArray();
                    }
                }

                continue;
            }

            $dataset = $bot->datasets()
                ->wherePivot('is_enabled', true)
                ->where('datasets.team_id', $bot->team_id)
                ->whereKey($source['dataset_id'])
                ->first();

            if (! $dataset instanceof Dataset) {
                continue;
            }

            $records = DatasetRecord::query()
                ->active()
                ->where('dataset_id', $dataset->id)
                ->whereIn('id', $source['record_ids'])
                ->get()
                ->keyBy('id');

            foreach ($source['record_ids'] as $recordId) {
                if (count($cards) >= $maximum) {
                    break 2;
                }

                $key = $dataset->id.':'.$recordId;

                if (isset($seen[$key])) {
                    continue;
                }

                $record = $records->get($recordId);

                if (! $record instanceof DatasetRecord) {
                    continue;
                }

                $card = $this->format($bot, $dataset, $record);

                if (! $card instanceof ProductCard) {
                    continue;
                }

                $seen[$key] = true;
                $cards[] = $card->toArray();
            }
        }

        $this->cycleLogger?->event('product_cards.mapped', [
            'source_count' => count($sources),
            'candidate_count' => $candidateCount,
            'product_card_count' => count($cards),
        ]);

        return $cards;
    }

    /**
     * Format an already normalized product returned by a live operation.
     * Live responses never need a local Dataset or a Typesense record.
     *
     * @param  array<string, mixed>  $item
     */
    private function formatLiveItem(Bot $bot, array $item): ?ProductCard
    {
        $title = $this->stringValue($item['title'] ?? null);

        if ($title === null) {
            return null;
        }

        $price = $this->primitiveValue($item['price'] ?? null);
        $oldPrice = $this->primitiveValue($item['old_price'] ?? null);

        if (! is_numeric($price) || ! is_numeric($oldPrice) || (float) $oldPrice <= (float) $price) {
            $oldPrice = null;
        }

        $template = $bot->cardTemplates()->whereNull('dataset_id')->first();

        return new ProductCard(
            id: (string) ($item['id'] ?? sha1($title)),
            image: $this->safeUrl($item['image_url'] ?? null),
            title: $title,
            subtitle: $this->stringValue($item['subtitle'] ?? null),
            description: $this->stringValue($item['description'] ?? null),
            price: $price,
            oldPrice: $oldPrice,
            discount: $this->primitiveValue($item['discount'] ?? null),
            url: $this->safeUrl($item['product_url'] ?? null),
            buttonLabel: $this->stringValue(data_get($template?->layout, 'button_label')) ?? 'View product',
            styles: $template instanceof BotCardTemplate ? $this->styles($template) : $this->defaultStyles(),
        );
    }

    /** @return array{background_color: string, text_color: string, muted_text_color: string, price_color: string, old_price_color: string, discount_color: string, button_color: string, button_text_color: string} */
    private function defaultStyles(): array
    {
        return [
            'background_color' => '#ffffff',
            'text_color' => '#171717',
            'muted_text_color' => '#737373',
            'price_color' => '#7c3aed',
            'old_price_color' => '#737373',
            'discount_color' => '#7c3aed',
            'button_color' => '#171717',
            'button_text_color' => '#ffffff',
        ];
    }

    /**
     * @return array<int|string, DatasetField>
     */
    private function displayableFields(Dataset $dataset): array
    {
        $fields = [];

        foreach ($dataset->fields()->where('is_displayable', true)->get() as $field) {
            $fields[$field->id] = $field;
            $fields[$field->key] = $field;
        }

        return $fields;
    }

    /**
     * @param  array<int|string, DatasetField>  $fields
     */
    private function field(array $fields, mixed $reference): ?DatasetField
    {
        if (is_int($reference) || (is_string($reference) && ctype_digit($reference))) {
            $field = $fields[(int) $reference] ?? null;
        } elseif (is_string($reference)) {
            $field = $fields[$reference] ?? null;
        } else {
            $field = null;
        }

        return $field instanceof DatasetField ? $field : null;
    }

    /**
     * @param  array<int|string, DatasetField>  $fields
     * @param  array<int|string, mixed>  $payload
     * @return Collection<string, mixed>
     */
    private function mappedValues(BotCardTemplate $template, array $fields, array $payload): Collection
    {
        $values = new Collection;

        foreach ((array) $template->mapping as $slot => $fieldReference) {
            $field = $this->field($fields, $fieldReference);

            if (! $field instanceof DatasetField || ! array_key_exists($field->key, $payload)) {
                continue;
            }

            $value = $payload[$field->key];

            if (is_scalar($value)) {
                $values->put((string) $slot, $value);
            }
        }

        return $values;
    }

    private function template(Bot $bot, Dataset $dataset): ?BotCardTemplate
    {
        $template = $bot->cardTemplates()
            ->where(function ($query) use ($dataset): void {
                $query->where('dataset_id', $dataset->id)->orWhereNull('dataset_id');
            })
            ->orderByRaw('CASE WHEN dataset_id = ? THEN 0 ELSE 1 END', [$dataset->id])
            ->first();

        if ($template instanceof BotCardTemplate) {
            return $template;
        }

        return $this->automaticTemplate($dataset);
    }

    private function automaticTemplate(Dataset $dataset): ?BotCardTemplate
    {
        $mapping = [];
        $usedFieldIds = [];

        foreach ($this->cardSlots() as $slot => $configuration) {
            $field = collect($this->displayableFields($dataset))
                ->unique(fn (DatasetField $field): int => (int) $field->id)
                ->filter(fn (DatasetField $field): bool => ! in_array((int) $field->id, $usedFieldIds, true))
                ->sortByDesc(fn (DatasetField $field): int => $this->fieldScore(
                    $field,
                    $configuration['hints'],
                    $configuration['preferred_types'],
                ))
                ->first();

            if (! $field instanceof DatasetField) {
                continue;
            }

            $score = $this->fieldScore(
                $field,
                $configuration['hints'],
                $configuration['preferred_types'],
            );

            if ($score === 0) {
                continue;
            }

            $mapping[$slot] = (int) $field->id;
            $usedFieldIds[] = (int) $field->id;
        }

        return new BotCardTemplate([
            'mapping' => $mapping,
            'layout' => [
                'button_label' => 'View product',
            ],
        ]);
    }

    /**
     * @return array<string, array{hints: list<string>, preferred_types: list<string>}>
     */
    private function cardSlots(): array
    {
        return [
            'image' => [
                'hints' => ['image_url', 'image', 'thumbnail_url'],
                'preferred_types' => ['url', 'string'],
            ],
            'title' => [
                'hints' => ['name', 'title', 'product_name'],
                'preferred_types' => ['string'],
            ],
            'subtitle' => [
                'hints' => ['brand', 'category', 'manufacturer'],
                'preferred_types' => ['string'],
            ],
            'description' => [
                'hints' => ['description', 'summary'],
                'preferred_types' => ['string'],
            ],
            'price' => [
                'hints' => ['price', 'sale_price'],
                'preferred_types' => ['decimal', 'integer'],
            ],
            'old_price' => [
                'hints' => ['old_price', 'compare_price', 'original_price'],
                'preferred_types' => ['decimal', 'integer'],
            ],
            'discount' => [
                'hints' => ['discount', 'discount_percent'],
                'preferred_types' => ['decimal', 'integer', 'string'],
            ],
            'url' => [
                'hints' => ['url', 'product_url', 'link'],
                'preferred_types' => ['url', 'string'],
            ],
        ];
    }

    /**
     * Match the same semantic names used by the Design page's automatic mapper.
     * A compatible data type alone is not enough to map an arbitrary field.
     *
     * @param  list<string>  $hints
     * @param  list<string>  $preferredTypes
     */
    private function fieldScore(DatasetField $field, array $hints, array $preferredTypes): int
    {
        $values = collect([
            $field->key,
            $field->canonical_name,
            $field->label,
            $field->semantic_type,
        ])
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->map(fn (string $value): string => $this->normalizeHint($value));
        $normalizedHints = array_map(fn (string $hint): string => $this->normalizeHint($hint), $hints);
        $score = 0;

        foreach ($values as $value) {
            foreach ($normalizedHints as $hint) {
                if ($value === $hint) {
                    $score = max($score, 100);
                } elseif (str_contains($value, $hint) || str_contains($hint, $value)) {
                    $score = max($score, 70);
                }
            }
        }

        return $score > 0 && in_array((string) $field->data_type, $preferredTypes, true)
            ? $score + 10
            : $score;
    }

    private function normalizeHint(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower($value));
    }

    private function primitiveValue(mixed $value): int|float|string|null
    {
        return is_int($value) || is_float($value) || is_string($value) ? $value : null;
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function safeUrl(mixed $value): ?string
    {
        $value = $this->stringValue($value);

        if ($value === null) {
            return null;
        }

        $scheme = parse_url($value, PHP_URL_SCHEME);

        return in_array(strtolower((string) $scheme), ['http', 'https'], true) ? $value : null;
    }

    /**
     * @return array{background_color: string, text_color: string, muted_text_color: string, price_color: string, old_price_color: string, discount_color: string, button_color: string, button_text_color: string}
     */
    private function styles(BotCardTemplate $template): array
    {
        $rawStyles = data_get($template->getAttribute('layout'), 'card_style');
        $styles = is_array($rawStyles) ? $rawStyles : [];

        return [
            'background_color' => $this->color($styles['background_color'] ?? null, '#ffffff'),
            'text_color' => $this->color($styles['text_color'] ?? null, '#171717'),
            'muted_text_color' => $this->color($styles['muted_text_color'] ?? null, '#737373'),
            'price_color' => $this->color($styles['price_color'] ?? null, '#7c3aed'),
            'old_price_color' => $this->color($styles['old_price_color'] ?? null, '#737373'),
            'discount_color' => $this->color($styles['discount_color'] ?? null, '#7c3aed'),
            'button_color' => $this->color($styles['button_color'] ?? null, '#171717'),
            'button_text_color' => $this->color($styles['button_text_color'] ?? null, '#ffffff'),
        ];
    }

    private function color(mixed $value, string $fallback): string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1
            ? strtolower($value)
            : $fallback;
    }
}
