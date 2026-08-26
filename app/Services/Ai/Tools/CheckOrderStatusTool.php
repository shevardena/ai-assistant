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
use App\Services\Conversations\Blocks\OrderStatusBlock;
use App\Services\Conversations\ConversationFormService;
use Illuminate\Support\Str;
use Throwable;

class CheckOrderStatusTool implements BotTool
{
    private const OPERATION_IDENTIFIER = 'check_order_status';

    /**
     * @var list<string>
     */
    private const MODEL_INPUTS = [
        'order_reference',
        'email',
        'postal_code',
        'phone',
    ];

    public function __construct(
        private readonly RuntimeApiOperationResolver $operationResolver,
        private readonly RuntimeApiArgumentMapper $argumentMapper,
        private readonly RuntimeApiOperationExecutor $operationExecutor,
        private readonly ConversationFormService $conversationFormService,
    ) {}

    public function name(): string
    {
        return self::OPERATION_IDENTIFIER;
    }

    public function description(): string
    {
        return 'Retrieve the current live status of a customer order from the configured integration. Use this for order state, not carrier tracking progress.';
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
                'email' => ['type' => 'string'],
                'postal_code' => ['type' => 'string'],
                'phone' => ['type' => 'string'],
            ],
            'required' => ['order_reference'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Determine whether the Bot has a complete model-input-only order operation.
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
        $orderMapping = $this->argumentMapper->mappingFor(
            $runtimeOperation->attachment,
            'order_reference',
        );

        if ($schema === null
            || $orderMapping === null
            || ! array_key_exists($orderMapping['operation_argument'], $schema['properties'])
            || ! $this->mappingsMatchSchema(
                $runtimeOperation->attachment,
                $schema['properties'],
            )) {
            return false;
        }

        foreach ($schema['required'] as $requiredArgument) {
            if (! array_key_exists($requiredArgument, $schema['properties'])) {
                return false;
            }

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

        $orderReference = $this->stringInput($arguments['order_reference'] ?? null);

        if ($orderReference === null) {
            return $this->invalidRequest();
        }

        if ((int) $context->bot->id !== (int) $bot->id
            || (int) $context->team->id !== (int) $bot->team_id) {
            return $this->invalidRequest();
        }

        $modelInputs = ['order_reference' => $orderReference];

        foreach (['email', 'postal_code', 'phone'] as $input) {
            if (! array_key_exists($input, $arguments)) {
                continue;
            }

            $value = $this->stringInput($arguments[$input]);

            if ($value === null) {
                return $this->invalidRequest();
            }

            $modelInputs[$input] = $value;
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

            if ($schema === null) {
                return $this->integrationUnavailable();
            }

            if (! $this->mappingsMatchSchema(
                $runtimeOperation->attachment,
                $schema['properties'],
            )) {
                return $this->integrationUnavailable();
            }

            $missingInputs = $this->missingRequiredInputs(
                $runtimeOperation->attachment,
                $schema['required'],
                $modelInputs,
            );

            if ($missingInputs !== []) {
                return $this->conversationFormService->request(
                    $context,
                    self::OPERATION_IDENTIFIER,
                    $this->formDefinition($missingInputs),
                );
            }

            $operationArguments = $this->argumentMapper->map(
                $runtimeOperation->attachment,
                null,
                null,
                $modelInputs,
            );

            if ($operationArguments === null) {
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
            logger()->warning('AI order status lookup failed.', [
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
            && array_key_exists('order_reference', $arguments);
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
     * @param  list<string>  $requiredArguments
     * @param  array<string, string>  $modelInputs
     * @return list<string>
     */
    private function missingRequiredInputs(
        BotApiOperation $attachment,
        array $requiredArguments,
        array $modelInputs,
    ): array {
        $missing = [];

        foreach ($requiredArguments as $requiredArgument) {
            foreach (self::MODEL_INPUTS as $modelInput) {
                $mapping = $this->argumentMapper->mappingFor($attachment, $modelInput);

                if ($mapping !== null
                    && $mapping['source'] === 'model_input'
                    && $mapping['operation_argument'] === $requiredArgument
                    && ! array_key_exists($modelInput, $modelInputs)) {
                    $missing[] = $modelInput;
                }
            }
        }

        return array_values(array_unique($missing));
    }

    /**
     * @param  list<string>  $missingInputs
     * @return array<string, mixed>
     */
    private function formDefinition(array $missingInputs): array
    {
        $fields = [
            'email' => [
                'name' => 'email',
                'label' => 'Email used for the order',
                'type' => 'email',
                'required' => true,
                'placeholder' => 'you@example.com',
            ],
            'postal_code' => [
                'name' => 'postal_code',
                'label' => 'Billing postal code',
                'type' => 'text',
                'required' => true,
            ],
            'phone' => [
                'name' => 'phone',
                'label' => 'Phone number',
                'type' => 'tel',
                'required' => true,
            ],
            'order_reference' => [
                'name' => 'order_reference',
                'label' => 'Order reference',
                'type' => 'text',
                'required' => true,
            ],
        ];

        return [
            'title' => 'Verify order details',
            'description' => 'Enter the missing information so we can check the order.',
            'fields' => array_values(array_filter(
                array_map(
                    static fn (string $input): ?array => $fields[$input] ?? null,
                    $missingInputs,
                ),
            )),
            'submit_label' => 'Check order',
        ];
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
            $block = $this->orderStatusBlock($result->data);

            return ToolResult::success([
                'ok' => true,
                'order_status' => $result->data,
            ], blocks: $block instanceof OrderStatusBlock ? [$block->toArray()] : []);
        }

        return ToolResult::failure(
            match ($result->error) {
                'invalid_request' => 'invalid_request',
                'timeout' => 'timeout',
                'unavailable' => 'integration_unavailable',
                default => 'order_not_available',
            },
            match ($result->error) {
                'invalid_request' => 'The order status request could not be fulfilled with the supplied details.',
                'timeout' => 'The order status lookup timed out.',
                'unavailable' => 'Order status is temporarily unavailable.',
                default => 'The order status could not be retrieved.',
            },
        );
    }

    /**
     * Build a snapshot from the scalar fields already allow-listed by the runtime mapper.
     *
     * @param  array<int|string, mixed>  $mappedData
     */
    private function orderStatusBlock(array $mappedData): ?OrderStatusBlock
    {
        $status = $this->statusValue($mappedData['status'] ?? null);
        $fields = [];

        foreach ($mappedData as $key => $value) {
            if (! is_string($key)
                || $key === ''
                || $key === 'status'
                || mb_strlen($key) > OrderStatusBlock::MAX_KEY_LENGTH
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

        usort($fields, function (array $left, array $right): int {
            $priorityComparison = $this->fieldPriority($left['key']) <=> $this->fieldPriority($right['key']);

            return $priorityComparison !== 0
                ? $priorityComparison
                : strcmp(Str::lower($left['key']), Str::lower($right['key']));
        });

        $fields = array_slice($fields, 0, OrderStatusBlock::MAX_FIELDS);

        return $status === null && $fields === []
            ? null
            : new OrderStatusBlock($status, $fields);
    }

    private function statusValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== ''
            && mb_strlen($value) <= OrderStatusBlock::MAX_STATUS_LENGTH
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1
            ? $value
            : null;
    }

    private function scalarValue(mixed $value): int|float|string|bool|null
    {
        if (is_int($value) || is_bool($value) || $value === null) {
            return $value;
        }

        if (is_string($value)) {
            return mb_substr($value, 0, OrderStatusBlock::MAX_VALUE_STRING_LENGTH);
        }

        return is_float($value) && is_finite($value) ? $value : null;
    }

    private function fieldLabel(string $key): string
    {
        $label = match (Str::lower($key)) {
            'estimated_delivery' => 'Estimated delivery',
            'updated_at' => 'Updated',
            default => (string) Str::of($key)
                ->replace(['_', '-', '.', '/'], ' ')
                ->lower()
                ->ucfirst(),
        };

        return Str::limit($label, OrderStatusBlock::MAX_LABEL_LENGTH, '');
    }

    private function fieldPriority(string $key): int
    {
        return match (Str::lower($key)) {
            'estimated_delivery' => 0,
            'updated_at' => 1,
            default => 2,
        };
    }

    private function invalidRequest(): ToolResult
    {
        return ToolResult::failure(
            'invalid_request',
            'The order status request could not be fulfilled with the supplied details.',
        );
    }

    private function integrationUnavailable(): ToolResult
    {
        return ToolResult::failure(
            'integration_unavailable',
            'Order status is temporarily unavailable.',
        );
    }
}
