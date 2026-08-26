<?php

namespace App\Services\Ai\Tools;

use App\Models\ApiOperation;
use App\Models\Bot;
use App\Models\BotApiOperation;
use App\Models\Conversation;
use App\Models\ConversationState;
use App\Models\Dataset;
use App\Models\DatasetRecord;
use App\Models\ToolRun;
use App\Services\Ai\CatalogRecordResolver;
use App\Services\Ai\ToolRunPayloadSanitizer;
use App\Services\Ai\Tools\Contracts\BotTool;
use App\Services\Ai\Tools\Contracts\ConfirmableBotTool;
use App\Services\Ai\WriteActionManager;
use App\Services\Api\RuntimeApiArgumentMapper;
use App\Services\Api\RuntimeApiOperation;
use App\Services\Api\RuntimeApiOperationExecutor;
use App\Services\Api\RuntimeApiOperationResolver;
use Illuminate\Support\Str;
use Throwable;

class AddToCartTool implements BotTool, ConfirmableBotTool
{
    private const OPERATION_IDENTIFIER = 'add_to_cart';

    private const STOCK_OPERATION_IDENTIFIER = 'check_stock';

    /**
     * The strict schema uses a fixed, bounded option vocabulary. The external
     * operation mapping still decides which selected values are sent onward.
     *
     * @var list<string>
     */
    private const OPTION_KEYS = [
        'color',
        'size',
        'memory',
        'finish',
        'material',
        'storage',
        'capacity',
        'style',
        'configuration',
        'variant',
    ];

    public function __construct(
        private readonly CatalogRecordResolver $recordResolver,
        private readonly RuntimeApiOperationResolver $operationResolver,
        private readonly RuntimeApiArgumentMapper $argumentMapper,
        private readonly RuntimeApiOperationExecutor $operationExecutor,
        private readonly WriteActionManager $actionManager,
        private readonly ToolRunPayloadSanitizer $payloadSanitizer,
    ) {}

    public function name(): string
    {
        return self::OPERATION_IDENTIFIER;
    }

    public function description(): string
    {
        return 'Propose adding an authorized catalog product to the current cart after explicit confirmation.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(Bot $bot): array
    {
        $options = [];

        foreach (self::OPTION_KEYS as $key) {
            $options[$key] = ['type' => 'string'];
        }

        return [
            'type' => 'object',
            'properties' => [
                'product_reference' => ['type' => 'string'],
                'quantity' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 100,
                ],
                'variant_reference' => ['type' => 'string'],
                'options' => [
                    'type' => 'object',
                    'properties' => $options,
                    'additionalProperties' => false,
                ],
            ],
            'required' => ['product_reference'],
            'additionalProperties' => false,
        ];
    }

    public function isAvailable(Bot $bot): bool
    {
        $runtimeOperation = $this->operationResolver->resolveWrite($bot, self::OPERATION_IDENTIFIER);

        if (! $runtimeOperation instanceof RuntimeApiOperation) {
            return false;
        }

        $mappings = $this->configuredMappings($runtimeOperation->attachment);

        if ($mappings === null
            || ! array_key_exists('product_reference', $mappings)
            || ! array_key_exists('quantity', $mappings)
            || ! $this->requiredArgumentsAreMapped($runtimeOperation->operation, $mappings)) {
            return false;
        }

        $productMapping = $mappings['product_reference'];

        if ($productMapping['source'] !== 'dataset_field' || $productMapping['dataset_field'] === null) {
            return false;
        }

        return $bot->datasets()
            ->wherePivot('is_enabled', true)
            ->where('datasets.team_id', $bot->team_id)
            ->catalog()
            ->ready()
            ->whereHas('fields', fn ($query) => $query->where('key', $productMapping['dataset_field']))
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(Bot $bot, array $arguments, ToolExecutionContext $context): ToolResult
    {
        $inputs = $this->validatedInputs($arguments);

        if ($inputs === null) {
            return ToolResult::failure(
                'invalid_arguments',
                'Provide a valid product reference, quantity, and supported flat options.',
            );
        }

        if (! $this->contextMatchesBot($bot, $context)) {
            return ToolResult::failure(
                'action_not_available',
                'This action is not available in the current conversation.',
            );
        }

        try {
            $prepared = $this->prepareOperation($bot, $context, $inputs);

            if ($prepared instanceof ToolResult) {
                return $prepared;
            }

            return $this->actionManager->propose(
                $bot,
                self::OPERATION_IDENTIFIER,
                $prepared['arguments'],
                $this->confirmationSummary($prepared['product_name'], $inputs),
                $context,
                $inputs,
            );
        } catch (Throwable $exception) {
            logger()->warning('AI add-to-cart proposal failed.', [
                'bot_id' => $bot->id,
                'team_id' => $bot->team_id,
                'tool' => self::OPERATION_IDENTIFIER,
                'exception' => $exception::class,
            ]);

            return $this->integrationUnavailable();
        }
    }

    /**
     * Confirm an add-to-cart action and re-check live stock immediately before writing.
     */
    public function confirm(Bot $bot, ToolExecutionContext $context, string $actionReference): ToolResult
    {
        $result = $this->actionManager->confirm(
            $bot,
            $context,
            $actionReference,
            fn (ToolRun $run): ?ToolResult => $this->revalidateBeforeWrite($bot, $context, $run),
        );

        return $this->persistCartReference($bot, $context, $actionReference, $result);
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @return array{arguments: array<string, mixed>, product_name: string}|ToolResult
     */
    private function prepareOperation(Bot $bot, ToolExecutionContext $context, array $inputs): array|ToolResult
    {
        $runtimeOperation = $this->operationResolver->resolveWrite($bot, self::OPERATION_IDENTIFIER);

        if (! $runtimeOperation instanceof RuntimeApiOperation) {
            return $this->integrationUnavailable();
        }

        $mappings = $this->configuredMappings($runtimeOperation->attachment);

        if ($mappings === null
            || ! array_key_exists('product_reference', $mappings)
            || ! array_key_exists('quantity', $mappings)
            || ! $this->requiredArgumentsAreMapped($runtimeOperation->operation, $mappings)) {
            return $this->integrationUnavailable();
        }

        $resolvedProduct = $this->recordResolver->resolve($bot, $inputs['product_reference']);

        if ($resolvedProduct === null) {
            return ToolResult::failure(
                'not_found',
                'The requested product could not be found.',
            );
        }

        if (! $this->variantIsValid(
            $runtimeOperation->attachment,
            $inputs,
            $resolvedProduct['dataset'],
            $resolvedProduct['record'],
            $mappings,
        )) {
            return $this->invalidRequest();
        }

        $stockResult = $this->checkStock($bot, $inputs, $resolvedProduct);

        if ($stockResult instanceof ToolResult) {
            return $stockResult;
        }

        $modelInputs = $this->modelInputs($inputs, $mappings);

        if ($modelInputs === null) {
            return $this->invalidRequest();
        }

        $operationArguments = $this->argumentMapper->map(
            $runtimeOperation->attachment,
            $resolvedProduct['dataset'],
            $resolvedProduct['record'],
            $modelInputs,
            $this->contextValues($context),
        );

        if ($operationArguments === null) {
            return $this->invalidRequest();
        }

        return [
            'arguments' => $operationArguments,
            'product_name' => $this->productName($resolvedProduct['record']),
        ];
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @param  array<string, array{source: string, dataset_field: string|null, model_input: string|null, context_key: string|null, operation_argument: string}>  $mappings
     * @return array<string, string|int|float|bool>|null
     */
    private function modelInputs(array $inputs, array $mappings): ?array
    {
        $modelInputs = [
            'product_reference' => $inputs['product_reference'],
            'quantity' => $inputs['quantity'],
        ];

        if (! array_key_exists('product_reference', $mappings)
            || ! array_key_exists('quantity', $mappings)) {
            return null;
        }

        if (array_key_exists('variant_reference', $inputs)) {
            if (! array_key_exists('variant_reference', $mappings)) {
                return null;
            }

            $modelInputs['variant_reference'] = $mappings['variant_reference']['source'] === 'model_input'
                ? $inputs['variant_reference']
                : '';
        } elseif (isset($mappings['variant_reference'])
            && $mappings['variant_reference']['source'] === 'dataset_field') {
            $modelInputs['variant_reference'] = '';
        }

        foreach ($inputs['options'] as $key => $value) {
            $modelInput = 'options.'.$key;

            if (! array_key_exists($modelInput, $mappings)
                || $mappings[$modelInput]['source'] !== 'model_input') {
                return null;
            }

            $modelInputs[$modelInput] = $value;
        }

        return $modelInputs;
    }

    /**
     * @return array<string, array{source: string, dataset_field: string|null, model_input: string|null, context_key: string|null, operation_argument: string}>|null
     */
    private function configuredMappings(BotApiOperation $attachment): ?array
    {
        $settings = $attachment->getAttribute('settings');
        $inputMapping = is_array($settings) ? ($settings['input_mapping'] ?? null) : null;

        if (! is_array($inputMapping) || $inputMapping === []) {
            return null;
        }

        $mappings = [];
        $operationArguments = [];

        foreach (array_keys($inputMapping) as $modelInput) {
            if (! is_string($modelInput)) {
                return null;
            }

            $isOption = Str::startsWith($modelInput, 'options.');
            $optionKey = $isOption ? substr($modelInput, strlen('options.')) : null;

            if (($isOption && ! is_string($optionKey))
                || ($isOption && ! in_array($optionKey, self::OPTION_KEYS, true))
                || (! $isOption && ! in_array($modelInput, [
                    'product_reference',
                    'quantity',
                    'variant_reference',
                    'cart_reference',
                ], true))) {
                return null;
            }

            $mapping = $this->argumentMapper->mappingFor($attachment, $modelInput);

            if ($mapping === null
                || ($isOption && $mapping['source'] !== 'model_input')
                || ($modelInput === 'product_reference' && $mapping['source'] !== 'dataset_field')
                || ($modelInput === 'quantity' && $mapping['source'] !== 'model_input')
                || ($modelInput === 'cart_reference' && ($mapping['source'] !== 'context_value'
                    || $mapping['context_key'] !== 'cart_reference'))
                || in_array($mapping['operation_argument'], $operationArguments, true)) {
                return null;
            }

            $mappings[$modelInput] = $mapping;
            $operationArguments[] = $mapping['operation_argument'];
        }

        return $mappings;
    }

    /**
     * Validate a requested variant against a field on the already authorized product.
     *
     * @param  array<string, mixed>  $inputs
     * @param  array<string, array{source: string, dataset_field: string|null, model_input: string|null, context_key: string|null, operation_argument: string}>  $mappings
     */
    private function variantIsValid(
        BotApiOperation $attachment,
        array $inputs,
        Dataset $dataset,
        DatasetRecord $record,
        array $mappings,
    ): bool {
        if (! array_key_exists('variant_reference', $inputs)) {
            return true;
        }

        $mapping = $mappings['variant_reference'] ?? null;

        if (! is_array($mapping)) {
            return false;
        }

        $fieldKey = $mapping['dataset_field'];

        if ($mapping['source'] === 'model_input') {
            $settings = $attachment->getAttribute('settings');
            $configuredField = is_array($settings) ? ($settings['variant_dataset_field'] ?? null) : null;

            if (! is_string($configuredField)
                || preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,254}$/', $configuredField) !== 1) {
                return false;
            }

            $fieldKey = $configuredField;
        }

        if (! is_string($fieldKey)) {
            return false;
        }

        $field = $dataset->relationLoaded('fields')
            ? $dataset->fields->firstWhere('key', $fieldKey)
            : $dataset->fields()->where('key', $fieldKey)->first();
        $payload = $record->getAttribute('payload');
        $expected = is_array($payload) && $field !== null ? ($payload[$field->key] ?? null) : null;

        return (is_string($expected) || is_int($expected) || is_float($expected))
            && (string) $expected === $inputs['variant_reference'];
    }

    /**
     * @param  array<string, array{source: string, dataset_field: string|null, model_input: string|null, context_key: string|null, operation_argument: string}>  $mappings
     */
    private function requiredArgumentsAreMapped(ApiOperation $operation, array $mappings): bool
    {
        $schema = $operation->getAttribute('request_schema');

        if (! is_array($schema) || ! is_array($schema['properties'] ?? null)) {
            return false;
        }

        $mappedArguments = [];

        foreach ($mappings as $mapping) {
            if (! array_key_exists($mapping['operation_argument'], $schema['properties'])) {
                return false;
            }

            $mappedArguments[] = $mapping['operation_argument'];
        }

        foreach ((array) ($schema['required'] ?? []) as $requiredArgument) {
            if (! is_string($requiredArgument)
                || ! in_array($requiredArgument, $mappedArguments, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @param  array{dataset: Dataset, record: DatasetRecord}  $resolvedProduct
     */
    private function checkStock(Bot $bot, array $inputs, array $resolvedProduct): ?ToolResult
    {
        $runtimeOperation = $this->operationResolver->resolveRead($bot, self::STOCK_OPERATION_IDENTIFIER);

        if (! $runtimeOperation instanceof RuntimeApiOperation) {
            return null;
        }

        $productMapping = $this->argumentMapper->mappingFor(
            $runtimeOperation->attachment,
            'product_reference',
        );

        if ($productMapping === null || $productMapping['source'] !== 'dataset_field') {
            return null;
        }

        $modelInputs = ['product_reference' => $inputs['product_reference']];
        $stockMappings = ['product_reference' => $productMapping];
        $quantityMapping = $this->argumentMapper->mappingFor($runtimeOperation->attachment, 'quantity');

        if ($quantityMapping !== null && $quantityMapping['source'] === 'model_input') {
            $modelInputs['quantity'] = $inputs['quantity'];
            $stockMappings['quantity'] = $quantityMapping;
        }

        if (! $this->requiredArgumentsAreMapped($runtimeOperation->operation, $stockMappings)) {
            return null;
        }

        $operationArguments = $this->argumentMapper->map(
            $runtimeOperation->attachment,
            $resolvedProduct['dataset'],
            $resolvedProduct['record'],
            $modelInputs,
        );

        if ($operationArguments === null) {
            return null;
        }

        $result = $this->operationExecutor->execute($runtimeOperation, $operationArguments);

        if (! $result->success) {
            return $this->integrationUnavailable();
        }

        if ($this->stockIsInsufficient($result->data, $inputs['quantity'])) {
            return ToolResult::failure(
                'out_of_stock',
                'The requested quantity is not currently available.',
            );
        }

        return null;
    }

    /**
     * @param  array<int|string, mixed>  $stock
     */
    private function stockIsInsufficient(array $stock, int $quantity): bool
    {
        foreach (['available', 'in_stock'] as $key) {
            if (array_key_exists($key, $stock) && $stock[$key] === false) {
                return true;
            }
        }

        foreach (['available_quantity', 'quantity'] as $key) {
            $available = $stock[$key] ?? null;

            if ((is_int($available) || is_float($available)) && $available < $quantity) {
                return true;
            }
        }

        return array_key_exists('stock', $stock) && $stock['stock'] === false;
    }

    private function revalidateBeforeWrite(Bot $bot, ToolExecutionContext $context, ToolRun $run): ?ToolResult
    {
        $safeArguments = $run->getAttribute('safe_arguments');
        $preflightArguments = is_array($safeArguments) ? ($safeArguments['__preflight'] ?? null) : null;

        if (! is_array($preflightArguments)) {
            return null;
        }

        $inputs = $this->validatedInputs($preflightArguments);

        if ($inputs === null) {
            return $this->invalidRequest();
        }

        $prepared = $this->prepareOperation($bot, $context, $inputs);

        if ($prepared instanceof ToolResult) {
            return $prepared;
        }

        $run->update([
            'safe_arguments' => $this->payloadSanitizer->sanitize([
                ...$prepared['arguments'],
                '__preflight' => $inputs,
            ]),
        ]);

        return null;
    }

    private function persistCartReference(
        Bot $bot,
        ToolExecutionContext $context,
        string $actionReference,
        ToolResult $result,
    ): ToolResult {
        if (($result->data['status'] ?? null) !== 'completed'
            || ! $context->conversation instanceof Conversation) {
            return $result;
        }

        $run = ToolRun::query()
            ->where('team_id', $bot->team_id)
            ->where('bot_id', $bot->id)
            ->where('action_reference', $actionReference)
            ->where('conversation_id', $context->conversation->id)
            ->where('visitor_id', $context->visitor?->id)
            ->first();

        if (! $run instanceof ToolRun) {
            return $result;
        }

        $safeResult = $run->getAttribute('safe_result');
        $outputKey = $this->cartReferenceOutput($bot, $run);
        $cartReference = is_array($safeResult) ? ($safeResult[$outputKey] ?? null) : null;

        if (! $this->isSafeCartReference($cartReference)) {
            return $result;
        }

        $state = $context->conversation->state()->first();
        $memory = $state?->getAttribute('memory');
        $memory = is_array($memory) ? $memory : [];
        $memory['cart_reference'] = $cartReference;

        ConversationState::query()->updateOrCreate(
            ['conversation_id' => $context->conversation->id],
            ['memory' => $memory],
        );

        $safeResult = is_array($safeResult) ? $safeResult : [];
        unset($safeResult[$outputKey]);
        $safeResult = $this->payloadSanitizer->sanitize($safeResult);
        $run->update(['safe_result' => $safeResult]);

        $data = $result->data;
        $data['result'] = $safeResult;
        $confirmationResult = [];

        foreach ($safeResult as $key => $value) {
            if (is_string($key)) {
                $confirmationResult[$key] = $value;
            }
        }

        return ToolResult::success($data, $result->metadata, $result->blocks)
            ->withConfirmationResult($confirmationResult);
    }

    /**
     * @return array<string, scalar|null>
     */
    private function contextValues(ToolExecutionContext $context): array
    {
        $cartReference = $this->cartReference($context);

        return $cartReference === null ? [] : ['cart_reference' => $cartReference];
    }

    private function cartReference(ToolExecutionContext $context): ?string
    {
        if (! $context->conversation instanceof Conversation) {
            return null;
        }

        $state = $context->conversation->state()->first();
        $memory = $state?->getAttribute('memory');
        $reference = is_array($memory) ? ($memory['cart_reference'] ?? null) : null;

        return $this->isSafeCartReference($reference) ? $reference : null;
    }

    private function isSafeCartReference(mixed $reference): bool
    {
        return is_string($reference)
            && trim($reference) !== ''
            && mb_strlen($reference) <= 255
            && preg_match('/[\x00-\x1F\x7F]/', $reference) !== 1;
    }

    private function cartReferenceOutput(Bot $bot, ToolRun $run): string
    {
        $attachment = BotApiOperation::query()
            ->where('bot_id', $bot->id)
            ->where('api_operation_id', $run->api_operation_id)
            ->where('tool_name', self::OPERATION_IDENTIFIER)
            ->first();
        $settings = $attachment?->getAttribute('settings');
        $configured = is_array($settings) ? ($settings['cart_reference_output'] ?? null) : null;

        return is_string($configured)
            && preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,99}$/', $configured) === 1
            ? $configured
            : 'cart_reference';
    }

    private function productName(DatasetRecord $record): string
    {
        $payload = $record->getAttribute('payload');
        $name = is_array($payload) ? ($payload['name'] ?? $payload['title'] ?? null) : null;

        return is_string($name) && trim($name) !== ''
            ? Str::limit(trim($name), 120, '')
            : 'the selected product';
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{product_reference: string, quantity: int, variant_reference?: string, options: array<string, string|int|float|bool>}|null
     */
    private function validatedInputs(array $arguments): ?array
    {
        if (array_diff(array_keys($arguments), [
            'product_reference',
            'quantity',
            'variant_reference',
            'options',
        ]) !== []) {
            return null;
        }

        $reference = $arguments['product_reference'] ?? null;

        if (! is_string($reference)
            || ($reference = trim($reference)) === ''
            || mb_strlen($reference) > 255
            || preg_match('/[\x00-\x1F\x7F]/', $reference) === 1) {
            return null;
        }

        $quantity = $arguments['quantity'] ?? 1;

        if (! is_int($quantity) || $quantity < 1 || $quantity > 100) {
            return null;
        }

        $inputs = [
            'product_reference' => $reference,
            'quantity' => $quantity,
            'options' => [],
        ];

        if (array_key_exists('variant_reference', $arguments)) {
            $variant = $arguments['variant_reference'];

            if (! is_string($variant)
                || ($variant = trim($variant)) === ''
                || mb_strlen($variant) > 255
                || preg_match('/[\x00-\x1F\x7F]/', $variant) === 1) {
                return null;
            }

            $inputs['variant_reference'] = $variant;
        }

        if (array_key_exists('options', $arguments)) {
            $options = $arguments['options'];

            if (! is_array($options) || count($options) > 10) {
                return null;
            }

            foreach ($options as $key => $value) {
                if (! is_string($key)
                    || ! in_array($key, self::OPTION_KEYS, true)
                    || (! is_string($value) && ! is_int($value) && ! is_float($value) && ! is_bool($value))
                    || (is_string($value) && (trim($value) === ''
                        || mb_strlen($value) > 255
                        || preg_match('/[\x00-\x1F\x7F]/', $value) === 1))) {
                    return null;
                }

                $inputs['options'][$key] = $value;
            }
        }

        return $inputs;
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    private function confirmationSummary(string $productName, array $inputs): string
    {
        $summary = 'Add '.($inputs['quantity'] === 1 ? '' : $inputs['quantity'].' × ').$productName.' to your cart';

        if (($inputs['variant_reference'] ?? null) !== null) {
            $summary .= ' (variant '.Str::limit($inputs['variant_reference'], 80, '').')';
        }

        if ($inputs['options'] !== []) {
            $options = [];

            foreach ($inputs['options'] as $key => $value) {
                $options[] = $key.' '.Str::limit((string) $value, 60, '');
            }

            $summary .= ' with '.implode(', ', $options);
        }

        return $summary.'.';
    }

    private function contextMatchesBot(Bot $bot, ToolExecutionContext $context): bool
    {
        if ((int) $context->bot->id !== (int) $bot->id
            || (int) $context->team->id !== (int) $bot->team_id) {
            return false;
        }

        if ($context->conversation
            && ((int) $context->conversation->bot_id !== (int) $bot->id
                || (int) $context->conversation->visitor_id !== (int) ($context->visitor?->id))) {
            return false;
        }

        return ! $context->userMessage
            || ($context->userMessage->role === 'user'
                && (int) $context->userMessage->conversation_id === (int) ($context->conversation?->id));
    }

    private function invalidRequest(): ToolResult
    {
        return ToolResult::failure(
            'invalid_request',
            'The cart request could not be fulfilled with the configured product and cart mappings.',
        );
    }

    private function integrationUnavailable(): ToolResult
    {
        return ToolResult::failure(
            'integration_unavailable',
            'Adding items to the cart is not currently available.',
        );
    }
}
