<?php

namespace App\Services\Ai\Tools;

use App\Models\Bot;
use App\Services\Ai\CatalogRecordResolver;
use App\Services\Ai\Formatters\DatasetRecordSafeFormatter;
use App\Services\Ai\Tools\Contracts\BotTool;
use App\Services\Cards\ProductCard;
use App\Services\Cards\ProductCardFormatter;
use Throwable;

class GetProductDetailsTool implements BotTool
{
    public function __construct(
        private readonly CatalogRecordResolver $recordResolver,
        private readonly DatasetRecordSafeFormatter $safeFormatter,
        private readonly ProductCardFormatter $cardFormatter,
    ) {}

    public function name(): string
    {
        return 'get_product_details';
    }

    public function description(): string
    {
        return 'Retrieve safe detailed information for one specific product previously referenced or identified in the catalog.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(Bot $bot): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'product_reference' => ['type' => 'string'],
            ],
            'required' => ['product_reference'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(Bot $bot, array $arguments, ToolExecutionContext $context): ToolResult
    {
        $reference = $this->reference($arguments);

        if ($reference === null
            || (int) $context->bot->id !== (int) $bot->id
            || (int) $context->team->id !== (int) $bot->team_id) {
            return $this->notFound();
        }

        try {
            $resolved = $this->recordResolver->resolve($bot, $reference);

            if ($resolved === null) {
                return $this->notFound();
            }

            $dataset = $resolved['dataset'];
            $record = $resolved['record'];

            $product = [
                'reference' => $reference,
                ...$this->safeFormatter->fields($dataset, $record),
            ];
            $card = $this->cardFormatter->format($bot, $dataset, $record);

            return ToolResult::success(
                data: [
                    'ok' => true,
                    'product' => $product,
                ],
                metadata: $card instanceof ProductCard
                    ? [
                        'card_source' => [
                            'dataset_id' => (int) $dataset->id,
                            'record_ids' => [(int) $record->id],
                        ],
                    ]
                    : [],
            );
        } catch (Throwable $exception) {
            logger()->warning('AI product details lookup failed.', [
                'bot_id' => $bot->id,
                'team_id' => $bot->team_id,
                'tool' => $this->name(),
                'exception' => $exception::class,
            ]);
            report($exception);

            return $this->notFound();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function reference(array $arguments): ?string
    {
        $reference = $arguments['product_reference'] ?? null;

        if (! is_string($reference)) {
            return null;
        }

        $reference = trim($reference);

        if ($reference === ''
            || mb_strlen($reference) > 255
            || preg_match('/[\x00-\x1F\x7F]/', $reference) === 1) {
            return null;
        }

        return $reference;
    }

    private function notFound(): ToolResult
    {
        return ToolResult::failure(
            'not_found',
            'The requested product could not be found.',
        );
    }
}
