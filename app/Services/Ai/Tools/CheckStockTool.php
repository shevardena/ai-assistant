<?php

namespace App\Services\Ai\Tools;

use App\Models\Bot;
use App\Services\Ai\CatalogRecordResolver;
use App\Services\Ai\Tools\Contracts\BotTool;
use App\Services\Api\RuntimeApiArgumentMapper;
use App\Services\Api\RuntimeApiOperationExecutor;
use App\Services\Api\RuntimeApiOperationResolver;
use App\Services\Api\RuntimeApiResult;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class CheckStockTool implements BotTool
{
    private const OPERATION_IDENTIFIER = 'check_stock';

    public function __construct(
        private readonly CatalogRecordResolver $recordResolver,
        private readonly RuntimeApiOperationResolver $operationResolver,
        private readonly RuntimeApiArgumentMapper $argumentMapper,
        private readonly RuntimeApiOperationExecutor $operationExecutor,
    ) {}

    public function name(): string
    {
        return self::OPERATION_IDENTIFIER;
    }

    public function description(): string
    {
        return 'Retrieve current live stock availability for one identified catalog product through its authorized inventory integration.';
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
     * Determine whether the Bot has a usable catalog and configured stock input mapping.
     */
    public function isAvailable(Bot $bot): bool
    {
        $operation = $this->operationResolver->resolve($bot, self::OPERATION_IDENTIFIER);

        if ($operation === null) {
            return false;
        }

        $mapping = $this->argumentMapper->mappingFor(
            $operation->attachment,
            'product_reference',
        );

        if ($mapping === null || $mapping['source'] !== 'dataset_field' || $mapping['dataset_field'] === null) {
            return false;
        }

        return $bot->datasets()
            ->wherePivot('is_enabled', true)
            ->where('datasets.team_id', $bot->team_id)
            ->catalog()
            ->ready()
            ->whereHas('fields', fn (Builder $query): Builder => $query
                ->where('key', $mapping['dataset_field']))
            ->exists();
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
            $resolvedProduct = $this->recordResolver->resolve($bot, $reference);

            if ($resolvedProduct === null) {
                return $this->notFound();
            }

            $operation = $this->operationResolver->resolve($bot, self::OPERATION_IDENTIFIER);

            if ($operation === null) {
                return $this->integrationUnavailable();
            }

            $arguments = $this->argumentMapper->map(
                $operation->attachment,
                $resolvedProduct['dataset'],
                $resolvedProduct['record'],
                ['product_reference' => $reference],
            );

            if ($arguments === null) {
                return $this->missingProductData();
            }

            return $this->result(
                $this->operationExecutor->execute($operation, $arguments),
            );
        } catch (Throwable $exception) {
            logger()->warning('AI stock check failed.', [
                'bot_id' => $bot->id,
                'team_id' => $bot->team_id,
                'tool' => $this->name(),
                'exception' => $exception::class,
            ]);

            return $this->integrationUnavailable();
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function reference(array $arguments): ?string
    {
        if (array_diff(array_keys($arguments), ['product_reference']) !== []
            || ! array_key_exists('product_reference', $arguments)
            || ! is_string($arguments['product_reference'])) {
            return null;
        }

        $reference = trim($arguments['product_reference']);

        if ($reference === ''
            || mb_strlen($reference) > 255
            || preg_match('/[\x00-\x1F\x7F]/', $reference) === 1) {
            return null;
        }

        return $reference;
    }

    private function result(RuntimeApiResult $result): ToolResult
    {
        if ($result->success) {
            return ToolResult::success([
                'ok' => true,
                'stock' => $result->data,
            ]);
        }

        return ToolResult::failure(
            $result->error ?? 'integration_error',
            match ($result->error) {
                'timeout' => 'The live stock check timed out.',
                'unavailable' => 'Live stock is temporarily unavailable.',
                default => 'The live stock check could not be completed.',
            },
        );
    }

    private function notFound(): ToolResult
    {
        return ToolResult::failure(
            'not_found',
            'The requested product could not be found.',
        );
    }

    private function missingProductData(): ToolResult
    {
        return ToolResult::failure(
            'missing_product_data',
            'Live stock could not be checked for this product.',
        );
    }

    private function integrationUnavailable(): ToolResult
    {
        return ToolResult::failure(
            'integration_unavailable',
            'Live stock is temporarily unavailable.',
        );
    }
}
