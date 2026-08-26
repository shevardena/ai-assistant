<?php

namespace App\Services\Ai\Tools;

use App\Models\ApiOperation;
use App\Models\Bot;
use App\Models\BotApiOperation;
use App\Models\Dataset;
use App\Models\DatasetRecord;
use App\Services\Ai\CatalogRecordResolver;
use App\Services\Ai\Tools\Contracts\BotTool;
use App\Services\Ai\WriteActionManager;
use App\Services\Api\RuntimeApiArgumentMapper;
use App\Services\Api\RuntimeApiOperation;
use App\Services\Api\RuntimeApiOperationResolver;
use App\Services\Conversations\ConversationFormService;
use Illuminate\Support\Str;
use Throwable;

class CaptureLeadTool implements BotTool
{
    private const OPERATION_IDENTIFIER = 'capture_lead';

    /**
     * @var list<string>
     */
    private const MODEL_INPUTS = [
        'name',
        'email',
        'phone',
        'message',
        'product_reference',
    ];

    /**
     * @var array<string, int>
     */
    private const MAX_LENGTHS = [
        'name' => 255,
        'email' => 320,
        'phone' => 64,
        'message' => 2000,
        'product_reference' => 255,
    ];

    public function __construct(
        private readonly CatalogRecordResolver $recordResolver,
        private readonly RuntimeApiArgumentMapper $argumentMapper,
        private readonly RuntimeApiOperationResolver $operationResolver,
        private readonly WriteActionManager $actionManager,
        private readonly ConversationFormService $conversationFormService,
    ) {}

    public function name(): string
    {
        return self::OPERATION_IDENTIFIER;
    }

    public function description(): string
    {
        return 'Collect customer contact details and submit them to the configured lead integration after explicit confirmation.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(Bot $bot): array
    {
        $required = $this->requiredModelInputs($bot);

        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'email' => ['type' => 'string'],
                'phone' => ['type' => 'string'],
                'message' => ['type' => 'string'],
                'product_reference' => ['type' => 'string'],
            ],
            'required' => $required,
            'additionalProperties' => false,
        ];
    }

    /**
     * Determine whether this Bot has a complete, enabled write configuration.
     */
    public function isAvailable(Bot $bot): bool
    {
        $runtimeOperation = $this->operationResolver->resolveWrite($bot, self::OPERATION_IDENTIFIER);

        if (! $runtimeOperation instanceof RuntimeApiOperation) {
            return false;
        }

        $mappings = $this->configuredMappings($runtimeOperation->attachment);

        if ($mappings === null
            || $mappings === []
            || ! $this->requiredArgumentsAreMapped($runtimeOperation->operation, $mappings)) {
            return false;
        }

        $datasetFields = $this->datasetMappingFields($mappings);

        return $datasetFields === [] || $this->botHasCatalogFields($bot, $datasetFields);
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
                'Provide valid contact details using only the supported lead fields.',
            );
        }

        $runtimeOperation = $this->operationResolver->resolveWrite($bot, self::OPERATION_IDENTIFIER);

        if (! $runtimeOperation instanceof RuntimeApiOperation) {
            return $this->integrationUnavailable();
        }

        try {
            $mappings = $this->configuredMappings($runtimeOperation->attachment);

            if ($mappings === null
                || $mappings === []
                || ! $this->requiredArgumentsAreMapped($runtimeOperation->operation, $mappings)) {
                return $this->integrationUnavailable();
            }

            $missingInputs = array_values(array_diff(
                $this->requiredModelInputs($bot),
                array_keys($inputs),
            ));

            if ($missingInputs !== []) {
                return $this->conversationFormService->request(
                    $context,
                    self::OPERATION_IDENTIFIER,
                    $this->formDefinition($missingInputs),
                );
            }

            $datasetFields = $this->datasetMappingFields($mappings);
            $productReference = $inputs['product_reference'] ?? null;
            $resolvedProduct = null;

            if ($datasetFields !== [] && $productReference !== null) {
                $resolvedProduct = $this->recordResolver->resolve($bot, $productReference);

                if ($resolvedProduct === null) {
                    return ToolResult::failure(
                        'not_found',
                        'The referenced product could not be found.',
                    );
                }
            }

            if ($this->requiresProduct($runtimeOperation->operation, $mappings) && $resolvedProduct === null) {
                return ToolResult::failure(
                    'missing_product_data',
                    'A valid product reference is required for this request.',
                );
            }

            if ($productReference !== null && $datasetFields === []) {
                return ToolResult::failure(
                    'invalid_arguments',
                    'Product context is not configured for this lead integration.',
                );
            }

            $modelInputs = $this->mappedInputs($inputs, $mappings, $resolvedProduct !== null);

            if ($modelInputs === null) {
                return ToolResult::failure(
                    'invalid_arguments',
                    'The supplied lead fields are not configured for this integration.',
                );
            }

            $operationArguments = $this->argumentMapper->map(
                $runtimeOperation->attachment,
                $resolvedProduct['dataset'] ?? null,
                $resolvedProduct['record'] ?? null,
                $modelInputs,
            );

            if ($operationArguments === null) {
                return ToolResult::failure(
                    'missing_product_data',
                    'The lead could not be enriched from the referenced product.',
                );
            }

            return $this->actionManager->propose(
                $bot,
                self::OPERATION_IDENTIFIER,
                $operationArguments,
                $this->confirmationSummary($resolvedProduct),
                $context,
            );
        } catch (Throwable $exception) {
            logger()->warning('AI lead capture proposal failed.', [
                'bot_id' => $bot->id,
                'team_id' => $bot->team_id,
                'tool' => self::OPERATION_IDENTIFIER,
                'exception' => $exception::class,
            ]);

            return $this->integrationUnavailable();
        }
    }

    /**
     * @return array<string, array{source: string, dataset_field: string|null, model_input: string|null, operation_argument: string}>|null
     */
    private function configuredMappings(BotApiOperation $attachment): ?array
    {
        if (! $this->argumentMapper->hasValidMappings($attachment, self::MODEL_INPUTS)) {
            return null;
        }

        $settings = $attachment->getAttribute('settings');
        $inputMapping = is_array($settings) ? ($settings['input_mapping'] ?? []) : [];

        if (! is_array($inputMapping)) {
            return null;
        }

        $mappings = [];
        $operationArguments = [];

        foreach (array_keys($inputMapping) as $modelInput) {
            if (! is_string($modelInput) || ! in_array($modelInput, self::MODEL_INPUTS, true)) {
                return null;
            }

            $mapping = $this->argumentMapper->mappingFor($attachment, $modelInput);

            if ($mapping === null || in_array($mapping['operation_argument'], $operationArguments, true)) {
                return null;
            }

            if ($modelInput === 'product_reference' && $mapping['source'] !== 'dataset_field') {
                return null;
            }

            $mappings[$modelInput] = $mapping;
            $operationArguments[] = $mapping['operation_argument'];
        }

        return $mappings;
    }

    /**
     * @param  array<string, array{source: string, dataset_field: string|null, model_input: string|null, operation_argument: string}>  $mappings
     */
    private function requiredArgumentsAreMapped(ApiOperation $operation, array $mappings): bool
    {
        $schema = $operation->getAttribute('request_schema');
        $required = is_array($schema) ? ($schema['required'] ?? []) : [];
        $properties = is_array($schema) ? ($schema['properties'] ?? []) : null;

        if (! is_array($properties)) {
            return false;
        }

        foreach ($mappings as $mapping) {
            if (! array_key_exists($mapping['operation_argument'], $properties)) {
                return false;
            }
        }

        $mappedArguments = array_column($mappings, 'operation_argument');

        foreach ($required as $argument) {
            if (! is_string($argument) || ! in_array($argument, $mappedArguments, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Translate required operation arguments into model-facing input names.
     *
     * @return list<string>
     */
    private function requiredModelInputs(Bot $bot): array
    {
        $runtimeOperation = $this->operationResolver->resolveWrite($bot, self::OPERATION_IDENTIFIER);

        if (! $runtimeOperation instanceof RuntimeApiOperation) {
            return [];
        }

        $mappings = $this->configuredMappings($runtimeOperation->attachment);
        $schema = $runtimeOperation->operation->getAttribute('request_schema');
        $required = is_array($schema) ? ($schema['required'] ?? []) : [];

        if ($mappings === null || ! is_array($required)) {
            return [];
        }

        $requiredInputs = [];

        foreach (self::MODEL_INPUTS as $modelInput) {
            $mapping = $mappings[$modelInput] ?? null;

            if ($mapping !== null && in_array($mapping['operation_argument'], $required, true)) {
                $requiredInputs[] = $modelInput;
            }
        }

        return $requiredInputs;
    }

    /**
     * @param  list<string>  $missingInputs
     * @return array<string, mixed>
     */
    private function formDefinition(array $missingInputs): array
    {
        $fields = [
            'name' => [
                'name' => 'name',
                'label' => 'Name',
                'type' => 'text',
                'required' => true,
            ],
            'email' => [
                'name' => 'email',
                'label' => 'Email',
                'type' => 'email',
                'required' => true,
                'placeholder' => 'you@example.com',
            ],
            'phone' => [
                'name' => 'phone',
                'label' => 'Phone number',
                'type' => 'tel',
                'required' => true,
            ],
            'message' => [
                'name' => 'message',
                'label' => 'Message',
                'type' => 'textarea',
                'required' => true,
            ],
            'product_reference' => [
                'name' => 'product_reference',
                'label' => 'Product reference',
                'type' => 'text',
                'required' => true,
            ],
        ];

        return [
            'title' => 'Contact details',
            'description' => 'Share the details needed for us to follow up.',
            'fields' => array_values(array_filter(
                array_map(
                    static fn (string $input): ?array => $fields[$input] ?? null,
                    $missingInputs,
                ),
            )),
            'submit_label' => 'Continue',
        ];
    }

    /**
     * @param  array<string, array{source: string, dataset_field: string|null, model_input: string|null, operation_argument: string}>  $mappings
     * @return list<string>
     */
    private function datasetMappingFields(array $mappings): array
    {
        $fields = [];

        foreach ($mappings as $mapping) {
            if ($mapping['source'] === 'dataset_field' && is_string($mapping['dataset_field'])) {
                $fields[] = $mapping['dataset_field'];
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * @param  list<string>  $datasetFields
     */
    private function botHasCatalogFields(Bot $bot, array $datasetFields): bool
    {
        $datasets = $bot->datasets()
            ->wherePivot('is_enabled', true)
            ->where('datasets.team_id', $bot->team_id)
            ->catalog()
            ->ready()
            ->with('fields')
            ->get();

        foreach ($datasets as $dataset) {
            $availableFields = $dataset->fields->pluck('key')->all();

            if (count(array_diff($datasetFields, $availableFields)) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, string>|null
     */
    private function validatedInputs(array $arguments): ?array
    {
        if (array_diff(array_keys($arguments), self::MODEL_INPUTS) !== []) {
            return null;
        }

        $inputs = [];

        foreach ($arguments as $name => $value) {
            if (! is_string($value)) {
                return null;
            }

            $value = trim($value);

            if ($value === ''
                || mb_strlen($value) > (self::MAX_LENGTHS[$name] ?? 0)
                || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                return null;
            }

            if ($name === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                return null;
            }

            $inputs[$name] = $value;
        }

        return $inputs;
    }

    /**
     * @param  array<string, string>  $inputs
     * @param  array<string, array{source: string, dataset_field: string|null, model_input: string|null, operation_argument: string}>  $mappings
     * @return array<string, string>|null
     */
    private function mappedInputs(array $inputs, array $mappings, bool $hasProduct): ?array
    {
        $modelInputs = [];

        foreach ($inputs as $modelInput => $value) {
            $mapping = $mappings[$modelInput] ?? null;

            if ($modelInput === 'product_reference') {
                if (! $hasProduct) {
                    return null;
                }

                if ($mapping !== null) {
                    $modelInputs[$modelInput] = $value;
                }

                continue;
            }

            if ($mapping === null) {
                return null;
            }

            if ($mapping['source'] !== 'model_input') {
                return null;
            }

            $modelInputs[$modelInput] = $value;
        }

        foreach ($mappings as $modelInput => $mapping) {
            if ($mapping['source'] === 'model_input' && ! array_key_exists($modelInput, $modelInputs)) {
                continue;
            }

            if ($mapping['source'] === 'dataset_field'
                && $hasProduct
                && ! array_key_exists($modelInput, $modelInputs)) {
                $modelInputs[$modelInput] = $inputs[$modelInput] ?? '';
            }
        }

        return $modelInputs;
    }

    /**
     * @param  array<string, array{source: string, dataset_field: string|null, model_input: string|null, operation_argument: string}>  $mappings
     */
    private function requiresProduct(ApiOperation $operation, array $mappings): bool
    {
        $schema = $operation->getAttribute('request_schema');
        $required = is_array($schema) ? ($schema['required'] ?? []) : [];

        foreach ($mappings as $mapping) {
            if ($mapping['source'] === 'dataset_field'
                && in_array($mapping['operation_argument'], $required, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{dataset: Dataset, record: DatasetRecord}|null  $resolvedProduct
     */
    private function confirmationSummary(?array $resolvedProduct): string
    {
        $productLabel = $this->productLabel($resolvedProduct);

        return $productLabel === null
            ? 'Submit your contact details for follow-up.'
            : 'Submit your contact details for follow-up about '.Str::limit($productLabel, 100, '').'.';
    }

    /**
     * @param  array{dataset: Dataset, record: DatasetRecord}|null  $resolvedProduct
     */
    private function productLabel(?array $resolvedProduct): ?string
    {
        if ($resolvedProduct === null) {
            return null;
        }

        $payload = $resolvedProduct['record']->getAttribute('payload') ?? null;

        if (! is_array($payload)) {
            return null;
        }

        foreach (['name', 'product_name', 'title'] as $key) {
            $value = $payload[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function integrationUnavailable(): ToolResult
    {
        return ToolResult::failure(
            'integration_unavailable',
            'Lead capture is not currently available.',
        );
    }
}
