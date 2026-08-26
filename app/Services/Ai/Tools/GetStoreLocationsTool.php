<?php

namespace App\Services\Ai\Tools;

use App\Models\Bot;
use App\Models\BotApiOperation;
use App\Services\Ai\Tools\Contracts\BotTool;
use App\Services\Api\RuntimeApiArgumentMapper;
use App\Services\Api\RuntimeApiOperation;
use App\Services\Api\RuntimeApiOperationExecutor;
use App\Services\Api\RuntimeApiOperationResolver;
use App\Services\Api\RuntimeApiResult;
use App\Services\Conversations\Blocks\LocationsBlock;
use Throwable;

class GetStoreLocationsTool implements BotTool
{
    private const OPERATION_IDENTIFIER = 'get_store_locations';

    /**
     * @var list<string>
     */
    private const MODEL_INPUTS = [
        'query',
        'postal_code',
        'city',
        'country',
        'latitude',
        'longitude',
        'limit',
    ];

    public function __construct(
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
        return 'Search the configured store, branch, showroom, pickup-point, dealer, or office locations using user-supplied geographic information.';
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
                'postal_code' => ['type' => 'string'],
                'city' => ['type' => 'string'],
                'country' => ['type' => 'string'],
                'latitude' => [
                    'type' => 'number',
                    'minimum' => -90,
                    'maximum' => 90,
                ],
                'longitude' => [
                    'type' => 'number',
                    'minimum' => -180,
                    'maximum' => 180,
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 20,
                ],
            ],
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    /**
     * Determine whether the Bot has a complete model-input-only location operation.
     */
    public function isAvailable(Bot $bot): bool
    {
        $runtimeOperation = $this->operationResolver->resolve($bot, self::OPERATION_IDENTIFIER);

        if ($runtimeOperation === null
            || ! $this->argumentMapper->hasValidMappings(
                $runtimeOperation->attachment,
                self::MODEL_INPUTS,
                'model_input',
            )) {
            return false;
        }

        $schema = $this->operationSchema($runtimeOperation);

        if ($schema === null
            || ! $this->hasConfiguredInput($runtimeOperation->attachment)
            || ! $this->mappingsMatchSchema(
                $runtimeOperation->attachment,
                $schema['properties'],
            )) {
            return false;
        }

        foreach ($schema['required'] as $requiredArgument) {
            if (! $this->hasRequiredMapping(
                $runtimeOperation->attachment,
                $requiredArgument,
            )) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(Bot $bot, array $arguments, ToolExecutionContext $context): ToolResult
    {
        if (! $this->hasAllowedArguments($arguments)) {
            return $this->invalidRequest();
        }

        $modelInputs = [];

        foreach (self::MODEL_INPUTS as $input) {
            if (! array_key_exists($input, $arguments)) {
                continue;
            }

            $value = $this->valueFor($input, $arguments[$input]);

            if ($value === null) {
                return $this->invalidRequest();
            }

            $modelInputs[$input] = $value;
        }

        if ((int) $context->bot->id !== (int) $bot->id
            || (int) $context->team->id !== (int) $bot->team_id) {
            return $this->invalidRequest();
        }

        try {
            $runtimeOperation = $this->operationResolver->resolve($bot, self::OPERATION_IDENTIFIER);

            if ($runtimeOperation === null) {
                return $this->integrationUnavailable();
            }

            if (! $this->argumentMapper->hasValidMappings(
                $runtimeOperation->attachment,
                self::MODEL_INPUTS,
                'model_input',
            )) {
                return $this->integrationUnavailable();
            }

            $schema = $this->operationSchema($runtimeOperation);

            if ($schema === null
                || ! $this->hasConfiguredInput($runtimeOperation->attachment)
                || ! $this->mappingsMatchSchema(
                    $runtimeOperation->attachment,
                    $schema['properties'],
                )) {
                return $this->integrationUnavailable();
            }

            $operationArguments = $this->argumentMapper->map(
                $runtimeOperation->attachment,
                null,
                null,
                $modelInputs,
            );

            if ($operationArguments === null) {
                return $this->invalidRequest();
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
            logger()->warning('AI store location lookup failed.', [
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
        return array_diff(array_keys($arguments), self::MODEL_INPUTS) === [];
    }

    private function valueFor(string $input, mixed $value): string|int|float|null
    {
        if (in_array($input, ['query', 'postal_code', 'city', 'country'], true)) {
            return $this->stringInput($value);
        }

        if ($input === 'limit') {
            return is_int($value) && $value >= 1 && $value <= 20 ? $value : null;
        }

        if (! is_int($value) && ! is_float($value)) {
            return null;
        }

        if ($input === 'latitude' && ($value < -90 || $value > 90)) {
            return null;
        }

        if ($input === 'longitude' && ($value < -180 || $value > 180)) {
            return null;
        }

        return $value;
    }

    private function stringInput(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== ''
            && mb_strlen($value) <= 255
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1
            ? $value
            : null;
    }

    private function hasConfiguredInput(BotApiOperation $attachment): bool
    {
        foreach (self::MODEL_INPUTS as $modelInput) {
            if ($this->argumentMapper->mappingFor($attachment, $modelInput) !== null) {
                return true;
            }
        }

        return false;
    }

    private function hasRequiredMapping(
        BotApiOperation $attachment,
        string $requiredArgument,
    ): bool {
        foreach (self::MODEL_INPUTS as $modelInput) {
            $mapping = $this->argumentMapper->mappingFor($attachment, $modelInput);

            if ($mapping !== null
                && $mapping['source'] === 'model_input'
                && $mapping['operation_argument'] === $requiredArgument) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, array<string, mixed>>  $properties
     */
    private function mappingsMatchSchema(
        BotApiOperation $attachment,
        array $properties,
    ): bool {
        foreach (self::MODEL_INPUTS as $modelInput) {
            $mapping = $this->argumentMapper->mappingFor($attachment, $modelInput);

            if ($mapping !== null
                && ! array_key_exists($mapping['operation_argument'], $properties)) {
                return false;
            }
        }

        return true;
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
            if (! is_string($name) || ! array_key_exists($name, $properties)) {
                return null;
            }

            $required[] = $name;
        }

        return [
            'properties' => $properties,
            'required' => $required,
        ];
    }

    private function result(RuntimeApiResult $result): ToolResult
    {
        if ($result->success) {
            if (! $this->isList($result->data)) {
                return ToolResult::failure(
                    'integration_error',
                    'The location response could not be normalized safely.',
                );
            }

            $block = LocationsBlock::fromMappedCollection($result->data);

            return ToolResult::success([
                'ok' => true,
                'locations' => $result->data,
            ], blocks: $block instanceof LocationsBlock ? [$block->toArray()] : []);
        }

        return ToolResult::failure(
            match ($result->error) {
                'invalid_request' => 'invalid_request',
                'timeout' => 'timeout',
                'unavailable' => 'integration_unavailable',
                default => 'integration_error',
            },
            match ($result->error) {
                'invalid_request' => 'The location request could not be fulfilled with the supplied details.',
                'timeout' => 'The location lookup timed out.',
                'unavailable' => 'Location information is temporarily unavailable.',
                default => 'Location information could not be retrieved safely.',
            },
        );
    }

    /**
     * @param  array<int|string, mixed>  $values
     */
    private function isList(array $values): bool
    {
        $expectedKey = 0;

        foreach (array_keys($values) as $key) {
            if ($key !== $expectedKey) {
                return false;
            }

            $expectedKey++;
        }

        return true;
    }

    private function invalidRequest(): ToolResult
    {
        return ToolResult::failure(
            'invalid_request',
            'The location request could not be fulfilled with the supplied details.',
        );
    }

    private function integrationUnavailable(): ToolResult
    {
        return ToolResult::failure(
            'integration_unavailable',
            'Location information is temporarily unavailable.',
        );
    }
}
