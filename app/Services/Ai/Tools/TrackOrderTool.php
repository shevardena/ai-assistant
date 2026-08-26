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
use App\Services\Conversations\Blocks\TrackingBlock;
use Illuminate\Support\Str;
use Throwable;

class TrackOrderTool implements BotTool
{
    private const OPERATION_IDENTIFIER = 'track_order';

    /**
     * @var list<string>
     */
    private const MODEL_INPUTS = [
        'order_reference',
        'tracking_reference',
        'email',
        'postal_code',
        'phone',
    ];

    /**
     * @var list<string>
     */
    private const LOOKUP_REFERENCES = [
        'order_reference',
        'tracking_reference',
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
        return 'Retrieve live shipment, carrier, and tracking progress for an existing customer order. Use this for logistics and delivery progress, not general order state.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(Bot $bot): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order_reference' => ['type' => 'string'],
                'tracking_reference' => ['type' => 'string'],
                'email' => ['type' => 'string'],
                'postal_code' => ['type' => 'string'],
                'phone' => ['type' => 'string'],
            ],
            'required' => [],
            'anyOf' => [
                ['required' => ['order_reference']],
                ['required' => ['tracking_reference']],
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * Determine whether the Bot has a complete model-input-only tracking operation.
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
            || ! $this->hasLookupMapping($runtimeOperation->attachment, $schema['properties'])
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

            $value = $this->stringInput($arguments[$input]);

            if ($value === null) {
                return $this->invalidRequest();
            }

            $modelInputs[$input] = $value;
        }

        if (! $this->hasLookupInput($modelInputs)) {
            return $this->invalidRequest();
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
                || ! $this->hasLookupMapping($runtimeOperation->attachment, $schema['properties'])
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
            logger()->warning('AI order tracking lookup failed.', [
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

    /**
     * @param  array<string, mixed>  $modelInputs
     */
    private function hasLookupInput(array $modelInputs): bool
    {
        foreach (self::LOOKUP_REFERENCES as $reference) {
            if (array_key_exists($reference, $modelInputs)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, array<string, mixed>>  $properties
     */
    private function hasLookupMapping(
        BotApiOperation $attachment,
        array $properties,
    ): bool {
        foreach (self::LOOKUP_REFERENCES as $reference) {
            $mapping = $this->argumentMapper->mappingFor($attachment, $reference);

            if ($mapping !== null
                && array_key_exists($mapping['operation_argument'], $properties)) {
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
            $block = $this->trackingBlock($result->data);

            return ToolResult::success([
                'ok' => true,
                'tracking' => $result->data,
            ], blocks: $block instanceof TrackingBlock ? [$block->toArray()] : []);
        }

        return ToolResult::failure(
            match ($result->error) {
                'invalid_request' => 'invalid_request',
                'timeout' => 'timeout',
                'unavailable' => 'integration_unavailable',
                default => 'tracking_not_available',
            },
            match ($result->error) {
                'invalid_request' => 'The tracking request could not be fulfilled with the supplied details.',
                'timeout' => 'The tracking lookup timed out.',
                'unavailable' => 'Tracking information is temporarily unavailable.',
                default => 'Tracking information could not be retrieved.',
            },
        );
    }

    /**
     * Build a snapshot from the scalar fields already allow-listed by the runtime mapper.
     *
     * @param  array<int|string, mixed>  $mappedData
     */
    private function trackingBlock(array $mappedData): ?TrackingBlock
    {
        $consumed = [];
        $status = $this->promotedString($mappedData, ['status', 'tracking_status'], $consumed, TrackingBlock::MAX_STATUS_LENGTH);
        $carrier = $this->promotedString($mappedData, ['carrier'], $consumed, TrackingBlock::MAX_CARRIER_LENGTH);
        $trackingReference = $this->promotedString($mappedData, ['tracking_reference', 'tracking_number'], $consumed, TrackingBlock::MAX_REFERENCE_LENGTH);
        $estimatedDelivery = $this->promotedString($mappedData, ['estimated_delivery', 'eta'], $consumed, TrackingBlock::MAX_DATE_LENGTH);
        $latestEvent = $this->promotedString($mappedData, ['latest_event', 'latest_update'], $consumed, TrackingBlock::MAX_EVENT_LENGTH);
        $trackingUrl = $this->promotedUrl($mappedData, $consumed);
        $fields = [];

        foreach ($mappedData as $key => $value) {
            if (! is_string($key)
                || $key === ''
                || isset($consumed[$key])
                || mb_strlen($key) > TrackingBlock::MAX_KEY_LENGTH
                || preg_match('/[\x00-\x1F\x7F]/', $key) === 1) {
                continue;
            }

            $safeValue = $this->scalarValue($value);

            if ($safeValue === null && $value !== null) {
                continue;
            }

            $fields[] = [
                'key' => $key,
                'label' => $this->fieldLabel($key),
                'value' => $safeValue,
            ];
        }

        usort($fields, static fn (array $left, array $right): int => strcmp(
            Str::lower($left['key']),
            Str::lower($right['key']),
        ));
        $fields = array_slice($fields, 0, TrackingBlock::MAX_FIELDS);

        return $status === null
            && $carrier === null
            && $trackingReference === null
            && $estimatedDelivery === null
            && $latestEvent === null
            && $trackingUrl === null
            && $fields === []
            ? null
            : new TrackingBlock(
                status: $status,
                carrier: $carrier,
                trackingReference: $trackingReference,
                estimatedDelivery: $estimatedDelivery,
                latestEvent: $latestEvent,
                trackingUrl: $trackingUrl,
                fields: $fields,
            );
    }

    /**
     * @param  array<int|string, mixed>  $mappedData
     * @param  list<string>  $aliases
     * @param  array<string, bool>  $consumed
     */
    private function promotedString(
        array $mappedData,
        array $aliases,
        array &$consumed,
        int $maximum,
    ): ?string {
        foreach ($aliases as $alias) {
            if (! array_key_exists($alias, $mappedData)) {
                continue;
            }

            $consumed[$alias] = true;
            $value = $mappedData[$alias];

            if (! is_string($value)) {
                continue;
            }

            $value = trim($value);

            if ($value !== ''
                && mb_strlen($value) <= $maximum
                && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<int|string, mixed>  $mappedData
     * @param  array<string, bool>  $consumed
     */
    private function promotedUrl(array $mappedData, array &$consumed): ?string
    {
        if (! array_key_exists('tracking_url', $mappedData)) {
            return null;
        }

        $consumed['tracking_url'] = true;
        $value = $mappedData['tracking_url'];

        if (! is_string($value) || mb_strlen($value) > TrackingBlock::MAX_URL_LENGTH) {
            return null;
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $value : null;
    }

    private function scalarValue(mixed $value): int|float|string|bool|null
    {
        if (is_int($value) || is_bool($value) || $value === null) {
            return $value;
        }

        if (is_string($value)) {
            return mb_substr($value, 0, TrackingBlock::MAX_VALUE_STRING_LENGTH);
        }

        return is_float($value) && is_finite($value) ? $value : null;
    }

    private function fieldLabel(string $key): string
    {
        $label = (string) Str::of($key)
            ->replace(['_', '-', '.', '/'], ' ')
            ->lower()
            ->ucfirst();

        return Str::limit($label, TrackingBlock::MAX_LABEL_LENGTH, '');
    }

    private function invalidRequest(): ToolResult
    {
        return ToolResult::failure(
            'invalid_request',
            'The tracking request could not be fulfilled with the supplied details.',
        );
    }

    private function integrationUnavailable(): ToolResult
    {
        return ToolResult::failure(
            'integration_unavailable',
            'Tracking information is temporarily unavailable.',
        );
    }
}
