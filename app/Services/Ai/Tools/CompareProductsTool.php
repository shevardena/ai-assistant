<?php

namespace App\Services\Ai\Tools;

use App\Models\Bot;
use App\Models\Dataset;
use App\Models\DatasetField;
use App\Models\DatasetRecord;
use App\Services\Ai\CatalogRecordResolver;
use App\Services\Ai\Formatters\DatasetRecordSafeFormatter;
use App\Services\Ai\Tools\Contracts\BotTool;
use App\Services\Cards\ProductCard;
use App\Services\Cards\ProductCardFormatter;
use App\Services\Conversations\Blocks\ComparisonBlock;
use Illuminate\Support\Str;
use Throwable;

class CompareProductsTool implements BotTool
{
    public function __construct(
        private readonly CatalogRecordResolver $recordResolver,
        private readonly DatasetRecordSafeFormatter $safeFormatter,
        private readonly ProductCardFormatter $cardFormatter,
    ) {}

    public function name(): string
    {
        return 'compare_products';
    }

    public function description(): string
    {
        return 'Compare two or more specific products from authorized catalog records using their product references.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(Bot $bot): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'product_references' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'minItems' => 2,
                    'maxItems' => 5,
                ],
            ],
            'required' => ['product_references'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(Bot $bot, array $arguments, ToolExecutionContext $context): ToolResult
    {
        $references = $this->references($arguments);

        if ($references === null
            || (int) $context->bot->id !== (int) $bot->id
            || (int) $context->team->id !== (int) $bot->team_id) {
            return $this->invalidComparison();
        }

        try {
            $products = [];
            /** @var list<array{product_reference: string, dataset: Dataset, record: DatasetRecord, fields: array<string, mixed>, card: ProductCard|null}> $resolvedProducts */
            $resolvedProducts = [];
            /** @var list<array{dataset_id: int, record_ids: list<int>}> $cardSources */
            $cardSources = [];
            /** @var array<int, int> $cardSourceIndexes */
            $cardSourceIndexes = [];

            foreach ($references as $reference) {
                $resolved = $this->recordResolver->resolve($bot, $reference);

                if ($resolved === null) {
                    return $this->productsNotFound();
                }

                $dataset = $resolved['dataset'];
                $record = $resolved['record'];
                $fields = $this->safeFormatter->fields($dataset, $record);
                $card = $this->cardFormatter->format($bot, $dataset, $record);
                $products[] = [
                    'product_reference' => $reference,
                    'fields' => $fields,
                ];
                $resolvedProducts[] = [
                    'product_reference' => $reference,
                    'dataset' => $dataset,
                    'record' => $record,
                    'fields' => $fields,
                    'card' => $card,
                ];

                if (! $card instanceof ProductCard) {
                    continue;
                }

                $datasetId = (int) $dataset->id;

                if (! isset($cardSourceIndexes[$datasetId])) {
                    $cardSourceIndexes[$datasetId] = count($cardSources);
                    $cardSources[] = [
                        'dataset_id' => $datasetId,
                        'record_ids' => [],
                    ];
                }

                $cardSources[$cardSourceIndexes[$datasetId]]['record_ids'][] = (int) $record->id;
            }

            $metadata = $cardSources === [] ? [] : ['card_sources' => $cardSources];

            if (count($cardSources) === 1) {
                $metadata['card_source'] = $cardSources[0];
            }

            $comparison = $this->comparisonBlock($resolvedProducts);

            return ToolResult::success(
                data: [
                    'ok' => true,
                    'products' => $products,
                ],
                metadata: $metadata,
                blocks: $comparison instanceof ComparisonBlock
                    ? [$comparison->toArray()]
                    : [],
            );
        } catch (Throwable $exception) {
            logger()->warning('AI product comparison failed.', [
                'bot_id' => $bot->id,
                'team_id' => $bot->team_id,
                'tool' => $this->name(),
                'exception' => $exception::class,
            ]);
            report($exception);

            return $this->productsNotFound();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return list<string>|null
     */
    private function references(array $arguments): ?array
    {
        if (array_diff(array_keys($arguments), ['product_references']) !== []
            || ! array_key_exists('product_references', $arguments)
            || ! is_array($arguments['product_references'])
            || count($arguments['product_references']) < 2
            || count($arguments['product_references']) > 5) {
            return null;
        }

        $references = [];
        $seen = [];

        foreach ($arguments['product_references'] as $reference) {
            if (! is_string($reference)) {
                return null;
            }

            $reference = trim($reference);

            if ($reference === ''
                || mb_strlen($reference) > 255
                || preg_match('/[\x00-\x1F\x7F]/', $reference) === 1
                || isset($seen[$reference])) {
                return null;
            }

            $seen[$reference] = true;
            $references[] = $reference;
        }

        return $references;
    }

    private function invalidComparison(): ToolResult
    {
        return ToolResult::failure(
            'invalid_comparison',
            'Provide between 2 and 5 distinct product references to compare.',
        );
    }

    private function productsNotFound(): ToolResult
    {
        return ToolResult::failure(
            'products_not_found',
            'One or more requested products could not be found.',
        );
    }

    /**
     * Build the trusted comparison block from the records already resolved above.
     *
     * @param  list<array{product_reference: string, dataset: Dataset, record: DatasetRecord, fields: array<string, mixed>, card: ProductCard|null}>  $products
     */
    private function comparisonBlock(array $products): ?ComparisonBlock
    {
        if (count($products) < 2) {
            return null;
        }

        $items = [];
        /** @var array<string, array{key: string, label: string}> $fieldDefinitions */
        $fieldDefinitions = [];
        /** @var array<string, list<int|float|string|bool|null>> $fieldValues */
        $fieldValues = [];

        foreach ($products as $index => $product) {
            $items[] = [
                'product_reference' => $product['product_reference'],
                'label' => $this->itemLabel($product),
            ];

            foreach ($product['dataset']->fields as $field) {
                if (! $field->is_displayable) {
                    continue;
                }

                $key = (string) $field->key;

                if ($key === '') {
                    continue;
                }

                if (! isset($fieldDefinitions[$key])) {
                    $fieldDefinitions[$key] = [
                        'key' => $key,
                        'label' => $this->fieldLabel($field),
                    ];
                    $fieldValues[$key] = array_fill(0, count($products), null);
                }

                $fieldValues[$key][$index] = $this->comparisonValue($product['fields'][$key] ?? null);
            }
        }

        /** @var list<array{key: string, label: string, values: list<int|float|string|bool|null>}> $fields */
        $fields = [];

        foreach ($fieldDefinitions as $key => $definition) {
            $values = array_values($fieldValues[$key]);

            if (! in_array(true, array_map(static fn (mixed $value): bool => $value !== null, $values), true)) {
                continue;
            }

            $fields[] = [
                ...$definition,
                'values' => $values,
            ];

            if (count($fields) >= ComparisonBlock::MAX_FIELDS) {
                break;
            }
        }

        return $fields === [] ? null : new ComparisonBlock($items, $fields);
    }

    /**
     * @param  array{product_reference: string, dataset: Dataset, record: DatasetRecord, fields: array<string, mixed>, card: ProductCard|null}  $product
     */
    private function itemLabel(array $product): string
    {
        if ($product['card'] instanceof ProductCard) {
            return $product['card']->title;
        }

        foreach ($product['dataset']->fields as $field) {
            if (! $field->is_displayable || ! $this->isTitleField($field)) {
                continue;
            }

            $value = $product['fields'][(string) $field->key] ?? null;

            if (is_scalar($value)) {
                $value = trim((string) $value);

                if ($value !== '') {
                    return Str::limit($value, ComparisonBlock::MAX_LABEL_LENGTH, '');
                }
            }
        }

        return Str::limit((string) $product['product_reference'], ComparisonBlock::MAX_LABEL_LENGTH, '');
    }

    private function isTitleField(DatasetField $field): bool
    {
        $semanticType = strtolower(trim((string) $field->semantic_type));
        $canonicalName = strtolower(trim((string) $field->canonical_name));
        $key = strtolower(trim((string) $field->key));

        return $semanticType === 'name'
            || in_array($canonicalName, ['name', 'title', 'product_name'], true)
            || in_array($key, ['name', 'title', 'product_name'], true);
    }

    private function fieldLabel(DatasetField $field): string
    {
        $label = trim((string) $field->label);

        if ($label !== '') {
            return Str::limit($label, ComparisonBlock::MAX_LABEL_LENGTH, '');
        }

        $canonicalName = trim((string) $field->canonical_name);

        if ($canonicalName !== '') {
            return Str::limit(Str::headline($canonicalName), ComparisonBlock::MAX_LABEL_LENGTH, '');
        }

        return Str::limit(Str::headline((string) $field->key), ComparisonBlock::MAX_LABEL_LENGTH, '');
    }

    private function comparisonValue(mixed $value): int|float|string|bool|null
    {
        if (is_int($value) || is_bool($value) || is_string($value)) {
            return $value;
        }

        return is_float($value) && is_finite($value) ? $value : null;
    }
}
