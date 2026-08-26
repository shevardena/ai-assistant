<?php

namespace App\Services\Ai\Tools;

use App\Models\Bot;
use App\Models\BotApiOperation;
use App\Models\Dataset;
use App\Services\Ai\CatalogRecordResolver;
use App\Services\Ai\Tools\Contracts\BotTool;
use App\Services\Api\RuntimeApiArgumentMapper;
use App\Services\Api\RuntimeApiOperation;
use App\Services\Api\RuntimeApiOperationExecutor;
use App\Services\Api\RuntimeApiOperationResolver;
use App\Services\Api\RuntimeApiResult;
use Illuminate\Support\Collection;
use Throwable;

class GetShippingInfoTool implements BotTool
{
    private const OPERATION_IDENTIFIER = 'get_shipping_info';

    /**
     * @var list<string>
     */
    private const MODEL_INPUTS = [
        'product_reference',
        'postal_code',
        'country',
        'quantity',
    ];

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
        return 'Retrieve live shipping options, delivery estimates, or shipping costs for one authorized catalog product and destination.';
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
                'postal_code' => ['type' => 'string'],
                'country' => ['type' => 'string'],
                'quantity' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 100,
                ],
            ],
            'required' => ['product_reference'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Determine whether the Bot has a usable catalog and complete required mapping.
     */
    public function isAvailable(Bot $bot): bool
    {
        $runtimeOperation = $this->operationResolver->resolve($bot, self::OPERATION_IDENTIFIER);

        if ($runtimeOperation === null) {
            return false;
        }

        $schema = $this->operationSchema($runtimeOperation);
        $productMapping = $this->argumentMapper->mappingFor(
            $runtimeOperation->attachment,
            'product_reference',
        );

        if ($schema === null
            || ! $this->argumentMapper->hasValidMappings($runtimeOperation->attachment)
            || $productMapping === null
            || $productMapping['source'] !== 'dataset_field'
            || $productMapping['dataset_field'] === null) {
            return false;
        }

        $datasets = $this->catalogDatasets($bot);

        if ($datasets->isEmpty()) {
            return false;
        }

        foreach ($schema['required'] as $requiredArgument) {
            if (! $this->hasRequiredMapping(
                $runtimeOperation->attachment,
                $requiredArgument,
                $schema['properties'],
                $datasets,
            )) {
                return false;
            }
        }

        return $datasets->contains(
            fn (Dataset $dataset): bool => $dataset->fields->contains(
                fn ($field): bool => $field->key === $productMapping['dataset_field'],
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(Bot $bot, array $arguments, ToolExecutionContext $context): ToolResult
    {
        if (! $this->hasAllowedArguments($arguments)) {
            return $this->invalidRequest();
        }

        $reference = $this->reference($arguments['product_reference'] ?? null);

        if ($reference === null) {
            return $this->notFound();
        }

        if ((int) $context->bot->id !== (int) $bot->id
            || (int) $context->team->id !== (int) $bot->team_id) {
            return $this->invalidRequest();
        }

        $modelInputs = ['product_reference' => $reference];

        foreach (['postal_code', 'country'] as $input) {
            if (! array_key_exists($input, $arguments)) {
                continue;
            }

            if (! is_string($arguments[$input]) || $this->invalidString($arguments[$input])) {
                return $this->invalidRequest();
            }

            $modelInputs[$input] = trim($arguments[$input]);
        }

        if (array_key_exists('quantity', $arguments)) {
            if (! is_int($arguments['quantity'])
                || $arguments['quantity'] < 1
                || $arguments['quantity'] > 100) {
                return $this->invalidRequest();
            }

            $modelInputs['quantity'] = $arguments['quantity'];
        }

        try {
            $resolvedProduct = $this->recordResolver->resolve($bot, $reference);

            if ($resolvedProduct === null) {
                return $this->notFound();
            }

            $runtimeOperation = $this->operationResolver->resolve($bot, self::OPERATION_IDENTIFIER);

            if ($runtimeOperation === null) {
                return $this->integrationUnavailable();
            }

            $productMapping = $this->argumentMapper->mappingFor(
                $runtimeOperation->attachment,
                'product_reference',
            );

            if ($productMapping === null
                || $productMapping['source'] !== 'dataset_field'
                || $productMapping['dataset_field'] === null) {
                return $this->integrationUnavailable();
            }

            if (! $this->hasProductData(
                $resolvedProduct['dataset'],
                $resolvedProduct['record']->getAttribute('payload'),
                $productMapping['dataset_field'],
            )) {
                return $this->missingProductData();
            }

            $operationArguments = $this->argumentMapper->map(
                $runtimeOperation->attachment,
                $resolvedProduct['dataset'],
                $resolvedProduct['record'],
                $modelInputs,
            );

            if ($operationArguments === null) {
                return $this->invalidRequest();
            }

            $schema = $this->operationSchema($runtimeOperation);

            if ($schema === null) {
                return $this->integrationUnavailable();
            }

            foreach ($schema['required'] as $requiredArgument) {
                if (! array_key_exists($requiredArgument, $operationArguments)) {
                    return $this->invalidRequest();
                }
            }

            return $this->result(
                $this->operationExecutor->execute($runtimeOperation, $operationArguments),
            );
        } catch (Throwable $exception) {
            logger()->warning('AI shipping information lookup failed.', [
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
    private function hasAllowedArguments(array $arguments): bool
    {
        return array_diff(array_keys($arguments), self::MODEL_INPUTS) === []
            && array_key_exists('product_reference', $arguments);
    }

    private function reference(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $reference = trim($value);

        return $reference !== ''
            && mb_strlen($reference) <= 255
            && preg_match('/[\x00-\x1F\x7F]/', $reference) !== 1
            ? $reference
            : null;
    }

    private function invalidString(string $value): bool
    {
        return trim($value) === ''
            || mb_strlen($value) > 255
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1;
    }

    /**
     * @return Collection<int, Dataset>
     */
    private function catalogDatasets(Bot $bot): Collection
    {
        return $bot->datasets()
            ->wherePivot('is_enabled', true)
            ->where('datasets.team_id', $bot->team_id)
            ->catalog()
            ->ready()
            ->with('fields')
            ->get();
    }

    /**
     * @param  array<string, array<string, mixed>>  $properties
     * @param  Collection<int, Dataset>  $datasets
     */
    private function hasRequiredMapping(
        BotApiOperation $attachment,
        string $requiredArgument,
        array $properties,
        Collection $datasets,
    ): bool {
        foreach (self::MODEL_INPUTS as $modelInput) {
            $mapping = $this->argumentMapper->mappingFor($attachment, $modelInput);

            if ($mapping === null
                || $mapping['operation_argument'] !== $requiredArgument
                || ! array_key_exists($requiredArgument, $properties)) {
                continue;
            }

            if ($mapping['source'] === 'model_input') {
                return true;
            }

            return $datasets->contains(
                fn (Dataset $dataset): bool => $dataset->fields->contains(
                    fn ($field): bool => $field->key === $mapping['dataset_field'],
                ),
            );
        }

        return false;
    }

    /**
     * @return array{properties: array<string, array<string, mixed>>, required: list<string>}|null
     */
    private function operationSchema(RuntimeApiOperation $runtimeOperation): ?array
    {
        $schema = $runtimeOperation->operation->getAttribute('request_schema');

        if (! is_array($schema) || ! is_array($schema['properties'] ?? null)) {
            return null;
        }

        $properties = [];

        foreach ($schema['properties'] as $name => $definition) {
            if (! is_string($name) || ! is_array($definition)) {
                return null;
            }

            $properties[$name] = $definition;
        }

        $required = [];

        foreach ((array) ($schema['required'] ?? []) as $name) {
            if (! is_string($name)) {
                return null;
            }

            $required[] = $name;
        }

        return [
            'properties' => $properties,
            'required' => $required,
        ];
    }

    private function hasProductData(Dataset $dataset, mixed $payload, string $fieldKey): bool
    {
        if (! is_array($payload)) {
            return false;
        }

        $field = $dataset->relationLoaded('fields')
            ? $dataset->fields->firstWhere('key', $fieldKey)
            : $dataset->fields()->where('key', $fieldKey)->first();

        if ($field === null || ! array_key_exists($fieldKey, $payload)) {
            return false;
        }

        $value = $payload[$fieldKey];

        return is_scalar($value) && (! is_string($value) || trim($value) !== '');
    }

    private function result(RuntimeApiResult $result): ToolResult
    {
        if ($result->success) {
            return ToolResult::success([
                'ok' => true,
                'shipping' => $result->data,
            ]);
        }

        return ToolResult::failure(
            match ($result->error) {
                'invalid_request' => 'invalid_request',
                'timeout' => 'timeout',
                'unavailable' => 'integration_unavailable',
                default => 'integration_error',
            },
            match ($result->error) {
                'invalid_request' => 'The shipping request could not be fulfilled with the supplied details.',
                'timeout' => 'The shipping lookup timed out.',
                'unavailable' => 'Shipping information is temporarily unavailable.',
                default => 'The shipping lookup could not be completed safely.',
            },
        );
    }

    private function invalidRequest(): ToolResult
    {
        return ToolResult::failure(
            'invalid_request',
            'The shipping request could not be fulfilled with the supplied details.',
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
            'Shipping information could not be retrieved for this product.',
        );
    }

    private function integrationUnavailable(): ToolResult
    {
        return ToolResult::failure(
            'integration_unavailable',
            'Shipping information is temporarily unavailable.',
        );
    }
}
