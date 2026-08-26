<?php

namespace App\Services\Ai\Tools;

use App\Models\ApiOperation;
use App\Models\Bot;
use App\Models\BotApiOperation;
use App\Models\ToolRun;
use App\Services\Ai\ToolRunPayloadSanitizer;
use App\Services\Ai\Tools\Contracts\BotTool;
use App\Services\Ai\Tools\Contracts\ConfirmableBotTool;
use App\Services\Ai\WriteActionManager;
use App\Services\Api\RuntimeApiArgumentMapper;
use App\Services\Api\RuntimeApiOperation;
use App\Services\Api\RuntimeApiOperationExecutor;
use App\Services\Api\RuntimeApiOperationResolver;
use App\Services\Conversations\AppointmentSlotNormalizer;
use App\Services\Conversations\ConversationAppointmentService;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Str;
use Throwable;

class BookAppointmentTool implements BotTool, ConfirmableBotTool
{
    private const OPERATION_IDENTIFIER = 'book_appointment';

    private const DEFAULT_AVAILABILITY_OPERATION = 'appointment_availability';

    /**
     * @var list<string>
     */
    private const MODEL_INPUTS = [
        'start_at',
        'timezone',
        'service',
        'location',
        'name',
        'email',
        'phone',
        'notes',
    ];

    /**
     * @var list<string>
     */
    private const AVAILABILITY_INPUTS = [
        'start_at',
        'timezone',
        'service',
        'location',
    ];

    /**
     * @var array<string, int>
     */
    private const MAX_LENGTHS = [
        'start_at' => 40,
        'timezone' => 64,
        'service' => 255,
        'location' => 255,
        'name' => 255,
        'email' => 320,
        'phone' => 64,
        'notes' => 2000,
    ];

    public function __construct(
        private readonly RuntimeApiOperationResolver $operationResolver,
        private readonly RuntimeApiArgumentMapper $argumentMapper,
        private readonly RuntimeApiOperationExecutor $operationExecutor,
        private readonly WriteActionManager $actionManager,
        private readonly ToolRunPayloadSanitizer $payloadSanitizer,
        private readonly AppointmentSlotNormalizer $slotNormalizer,
        private readonly ConversationAppointmentService $appointmentService,
    ) {}

    public function name(): string
    {
        return self::OPERATION_IDENTIFIER;
    }

    public function description(): string
    {
        return 'Reserve a date and time through the configured appointment integration after explicit confirmation.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(Bot $bot): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'start_at' => ['type' => 'string'],
                'timezone' => ['type' => 'string'],
                'service' => ['type' => 'string'],
                'location' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'email' => ['type' => 'string'],
                'phone' => ['type' => 'string'],
                'notes' => ['type' => 'string'],
            ],
            'required' => $this->requiredModelInputs($bot),
            'additionalProperties' => false,
        ];
    }

    public function isAvailable(Bot $bot): bool
    {
        $runtimeOperation = $this->operationResolver->resolveWrite($bot, self::OPERATION_IDENTIFIER);

        if (! $runtimeOperation instanceof RuntimeApiOperation) {
            return false;
        }

        $mappings = $this->configuredMappings($runtimeOperation->attachment, self::MODEL_INPUTS);

        if ($mappings === null
            || $mappings === []
            || ! $this->requiredArgumentsAreMapped($runtimeOperation->operation, $mappings)) {
            return false;
        }

        if (! $this->hasExplicitAvailabilityConfiguration($runtimeOperation->attachment)) {
            return true;
        }

        $availabilityIdentifier = $this->availabilityIdentifier($runtimeOperation->attachment);

        return $availabilityIdentifier !== null
            && $this->operationResolver->resolveRead($bot, $availabilityIdentifier) instanceof RuntimeApiOperation;
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
                'Provide valid appointment details using only the supported booking fields.',
            );
        }

        $selectedSlot = $this->appointmentService->selectedForContext($context);

        if ($selectedSlot !== null) {
            $inputs['start_at'] = $selectedSlot['starts_at'];
            $inputs['timezone'] = $selectedSlot['timezone'];
        }

        $runtimeOperation = $this->operationResolver->resolveWrite($bot, self::OPERATION_IDENTIFIER);

        if (! $runtimeOperation instanceof RuntimeApiOperation) {
            return $this->integrationUnavailable();
        }

        try {
            $mappings = $this->configuredMappings($runtimeOperation->attachment, self::MODEL_INPUTS);

            if ($mappings === null
                || $mappings === []
                || ! $this->requiredArgumentsAreMapped($runtimeOperation->operation, $mappings)) {
                return $this->integrationUnavailable();
            }

            $schema = $this->operationSchema($runtimeOperation);
            $requiredInputs = [];

            if ($schema !== null) {
                foreach ($mappings as $modelInput => $mapping) {
                    if (in_array($mapping['operation_argument'], $schema['required'], true)) {
                        $requiredInputs[] = $modelInput;
                    }
                }
            }

            if (array_diff($requiredInputs, array_keys($inputs)) !== []) {
                return $this->invalidRequest();
            }

            $modelInputs = $this->mappedInputs($inputs, $mappings);

            if ($modelInputs === null) {
                return $this->invalidRequest();
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

            $availability = $this->checkAvailability(
                $bot,
                $runtimeOperation->attachment,
                $inputs,
                $context,
                $selectedSlot,
            );

            if ($availability['failure'] instanceof ToolResult) {
                return $availability['failure'];
            }

            if ($availability['appointment_block'] !== null) {
                return $availability['appointment_block'];
            }

            if ($availability['slot_reference'] !== null) {
                $slotArgument = $this->slotArgument($runtimeOperation->attachment);
                $schema = $this->operationSchema($runtimeOperation);

                if ($schema !== null && array_key_exists($slotArgument, $schema['properties'])) {
                    $operationArguments[$slotArgument] = $availability['slot_reference'];
                }
            }

            $preflightArguments = $availability['configured'] ? $inputs : null;

            return $this->actionManager->propose(
                $bot,
                self::OPERATION_IDENTIFIER,
                $operationArguments,
                $this->confirmationSummary($inputs),
                $context,
                $preflightArguments,
            );
        } catch (Throwable $exception) {
            logger()->warning('AI appointment proposal failed.', [
                'bot_id' => $bot->id,
                'team_id' => $bot->team_id,
                'tool' => self::OPERATION_IDENTIFIER,
                'exception' => $exception::class,
            ]);

            return $this->integrationUnavailable();
        }
    }

    /**
     * Confirm an appointment and revalidate availability immediately before booking.
     */
    public function confirm(Bot $bot, ToolExecutionContext $context, string $actionReference): ToolResult
    {
        return $this->actionManager->confirm(
            $bot,
            $context,
            $actionReference,
            fn (ToolRun $run): ?ToolResult => $this->revalidateBeforeWrite($bot, $run),
        );
    }

    /**
     * @param  list<string>  $allowedInputs
     * @return array<string, array{source: string, dataset_field: string|null, model_input: string|null, operation_argument: string}>|null
     */
    private function configuredMappings(BotApiOperation $attachment, array $allowedInputs): ?array
    {
        if (! $this->argumentMapper->hasValidMappings($attachment, $allowedInputs, 'model_input')) {
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
            if (! is_string($modelInput) || ! in_array($modelInput, $allowedInputs, true)) {
                return null;
            }

            $mapping = $this->argumentMapper->mappingFor($attachment, $modelInput);

            if ($mapping === null
                || $mapping['source'] !== 'model_input'
                || in_array($mapping['operation_argument'], $operationArguments, true)) {
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
        $schema = $this->operationSchemaFromModel($operation);

        if ($schema === null) {
            return false;
        }

        $mappedArguments = array_column($mappings, 'operation_argument');

        foreach ($schema['required'] as $requiredArgument) {
            if (! in_array($requiredArgument, $mappedArguments, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function requiredModelInputs(Bot $bot): array
    {
        $runtimeOperation = $this->operationResolver->resolveWrite($bot, self::OPERATION_IDENTIFIER);

        if (! $runtimeOperation instanceof RuntimeApiOperation) {
            return [];
        }

        $mappings = $this->configuredMappings($runtimeOperation->attachment, self::MODEL_INPUTS);
        $schema = $this->operationSchema($runtimeOperation);

        if ($mappings === null || $schema === null) {
            return [];
        }

        $requiredInputs = [];

        foreach (self::MODEL_INPUTS as $modelInput) {
            $mapping = $mappings[$modelInput] ?? null;

            if ($mapping !== null && in_array($mapping['operation_argument'], $schema['required'], true)) {
                $requiredInputs[] = $modelInput;
            }
        }

        return $requiredInputs;
    }

    /**
     * @param  array<string, string>  $inputs
     * @param  array<string, array{source: string, dataset_field: string|null, model_input: string|null, operation_argument: string}>  $mappings
     * @return array<string, string>|null
     */
    private function mappedInputs(array $inputs, array $mappings): ?array
    {
        foreach (array_keys($inputs) as $modelInput) {
            if (! array_key_exists($modelInput, $mappings)) {
                return null;
            }
        }

        return $inputs;
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

            if ($name === 'timezone' && ! $this->isIanaTimezone($value)) {
                return null;
            }

            $inputs[$name] = $value;
        }

        if (array_key_exists('start_at', $inputs)) {
            $normalizedStart = $this->normalizeStartAt(
                $inputs['start_at'],
                $inputs['timezone'] ?? null,
            );

            if ($normalizedStart === null) {
                return null;
            }

            $inputs['start_at'] = $normalizedStart;
        }

        return $inputs;
    }

    private function isIanaTimezone(string $timezone): bool
    {
        return in_array($timezone, DateTimeZone::listIdentifiers(), true);
    }

    private function normalizeStartAt(string $startAt, ?string $timezone): ?string
    {
        $offsetAware = preg_match(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/',
            $startAt,
        ) === 1;
        $local = preg_match(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:\.\d{1,6})?$/',
            $startAt,
        ) === 1;

        if (! $offsetAware && ! $local) {
            return null;
        }

        if ($offsetAware) {
            $normalized = str_ends_with($startAt, 'Z')
                ? substr($startAt, 0, -1).'+00:00'
                : $startAt;
            $hasSeconds = preg_match('/T\d{2}:\d{2}:/', $normalized) === 1;

            if (! $hasSeconds) {
                $normalized = substr($normalized, 0, 16).':00'.substr($normalized, 16);
            }

            $format = str_contains($normalized, '.') ? 'Y-m-d\TH:i:s.uP' : 'Y-m-d\TH:i:sP';
            $date = CarbonImmutable::createFromFormat($format, $normalized);

            if ($date === null || $this->hasDateErrors()) {
                return null;
            }

            return $timezone === null
                ? $date->format('Y-m-d\TH:i:sP')
                : $date->setTimezone(new DateTimeZone($timezone))->format('Y-m-d\TH:i:sP');
        }

        if ($timezone === null) {
            return null;
        }

        $hasSeconds = preg_match('/T\d{2}:\d{2}:/', $startAt) === 1;
        $normalized = $hasSeconds
            ? $startAt
            : substr($startAt, 0, 16).':00';
        $format = str_contains($normalized, '.') ? 'Y-m-d\TH:i:s.u' : 'Y-m-d\TH:i:s';
        $date = CarbonImmutable::createFromFormat($format, $normalized, new DateTimeZone($timezone));

        if ($date === null || $this->hasDateErrors()) {
            return null;
        }

        return $date->format('Y-m-d\TH:i:s') === substr($normalized, 0, 19)
            ? $date->format('Y-m-d\TH:i:sP')
            : null;
    }

    private function hasDateErrors(): bool
    {
        $errors = CarbonImmutable::getLastErrors();

        return is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0);
    }

    /**
     * @param  array<string, string>  $inputs
     * @param  array{provider_slot_reference: string, starts_at: string, ends_at: string|null, timezone: string}|null  $selectedSlot
     * @return array{configured: bool, slot_reference: string|null, appointment_block: ToolResult|null, failure: ToolResult|null}
     */
    private function checkAvailability(
        Bot $bot,
        BotApiOperation $bookingAttachment,
        array $inputs,
        ?ToolExecutionContext $context = null,
        ?array $selectedSlot = null,
        ?string $expectedSlotReference = null,
    ): array {
        $identifier = $this->availabilityIdentifier($bookingAttachment);

        if ($identifier === null) {
            return ['configured' => false, 'slot_reference' => null, 'appointment_block' => null, 'failure' => null];
        }

        if ($selectedSlot !== null) {
            return [
                'configured' => true,
                'slot_reference' => $selectedSlot['provider_slot_reference'],
                'appointment_block' => null,
                'failure' => null,
            ];
        }

        $runtimeOperation = $this->operationResolver->resolveRead($bot, $identifier);

        if (! $runtimeOperation instanceof RuntimeApiOperation) {
            if (! $this->hasExplicitAvailabilityConfiguration($bookingAttachment)) {
                return ['configured' => false, 'slot_reference' => null, 'appointment_block' => null, 'failure' => null];
            }

            return [
                'configured' => true,
                'slot_reference' => null,
                'appointment_block' => null,
                'failure' => $this->integrationUnavailable(),
            ];
        }

        $mappings = $this->configuredMappings($runtimeOperation->attachment, self::AVAILABILITY_INPUTS);
        $schema = $this->operationSchema($runtimeOperation);

        if ($mappings === null || $mappings === [] || $schema === null
            || ! $this->requiredArgumentsAreMapped($runtimeOperation->operation, $mappings)) {
            return [
                'configured' => true,
                'slot_reference' => null,
                'appointment_block' => null,
                'failure' => $this->integrationUnavailable(),
            ];
        }

        $readInputs = [];

        foreach ($inputs as $name => $value) {
            if (array_key_exists($name, $mappings)) {
                $readInputs[$name] = $value;
            }
        }

        $operationArguments = $this->argumentMapper->map(
            $runtimeOperation->attachment,
            null,
            null,
            $readInputs,
        );

        if ($operationArguments === null) {
            return [
                'configured' => true,
                'slot_reference' => null,
                'appointment_block' => null,
                'failure' => $this->invalidRequest(),
            ];
        }

        $result = $this->operationExecutor->execute($runtimeOperation, $operationArguments);

        if (! $result->success) {
            return [
                'configured' => true,
                'slot_reference' => null,
                'appointment_block' => null,
                'failure' => $result->error === 'invalid_request'
                    ? $this->invalidRequest()
                    : $this->integrationUnavailable(),
            ];
        }

        if (array_is_list($result->data)) {
            $slots = $this->slotNormalizer->normalize($result->data, $inputs['timezone'] ?? 'UTC');

            if ($slots === []) {
                return [
                    'configured' => true,
                    'slot_reference' => null,
                    'appointment_block' => null,
                    'failure' => $this->integrationUnavailable(),
                ];
            }

            if ($expectedSlotReference !== null) {
                $selected = collect($slots)->first(
                    static fn (array $slot): bool => $slot['provider_slot_reference'] === $expectedSlotReference,
                );

                return $selected === null
                    ? [
                        'configured' => true,
                        'slot_reference' => null,
                        'appointment_block' => null,
                        'failure' => ToolResult::failure(
                            'slot_unavailable',
                            'That appointment slot is no longer available.',
                        ),
                    ]
                    : [
                        'configured' => true,
                        'slot_reference' => $expectedSlotReference,
                        'appointment_block' => null,
                        'failure' => null,
                    ];
            }

            if ($context === null) {
                return [
                    'configured' => true,
                    'slot_reference' => null,
                    'appointment_block' => null,
                    'failure' => $this->integrationUnavailable(),
                ];
            }

            return [
                'configured' => true,
                'slot_reference' => null,
                'appointment_block' => $this->appointmentService->request(
                    $context,
                    self::OPERATION_IDENTIFIER,
                    (string) Str::uuid(),
                    'Available appointment times',
                    $inputs['timezone'] ?? 'UTC',
                    $slots,
                ),
                'failure' => null,
            ];
        }

        $availableKey = $this->availabilityOutput($bookingAttachment, 'availability_output');
        $available = $result->data[$availableKey] ?? null;

        if (! is_bool($available)) {
            return [
                'configured' => true,
                'slot_reference' => null,
                'appointment_block' => null,
                'failure' => $this->integrationUnavailable(),
            ];
        }

        if (! $available) {
            return [
                'configured' => true,
                'slot_reference' => null,
                'appointment_block' => null,
                'failure' => ToolResult::failure(
                    'slot_unavailable',
                    'That appointment slot is no longer available.',
                ),
            ];
        }

        $slotOutput = $this->availabilityOutput($bookingAttachment, 'availability_slot_output');
        $slot = $result->data[$slotOutput] ?? null;

        if ($slot !== null && (! is_string($slot) || trim($slot) === '' || mb_strlen($slot) > 255)) {
            return [
                'configured' => true,
                'slot_reference' => null,
                'appointment_block' => null,
                'failure' => $this->integrationUnavailable(),
            ];
        }

        return [
            'configured' => true,
            'slot_reference' => is_string($slot) ? $slot : null,
            'appointment_block' => null,
            'failure' => null,
        ];
    }

    private function revalidateBeforeWrite(Bot $bot, ToolRun $run): ?ToolResult
    {
        $safeArguments = $run->getAttribute('safe_arguments');
        $preflightArguments = is_array($safeArguments) ? ($safeArguments['__preflight'] ?? null) : null;

        if (! is_array($preflightArguments)) {
            return null;
        }

        $runtimeOperation = $this->operationResolver->resolveWrite($bot, self::OPERATION_IDENTIFIER);

        if (! $runtimeOperation instanceof RuntimeApiOperation) {
            return $this->integrationUnavailable();
        }

        $slotArgument = $this->slotArgument($runtimeOperation->attachment);
        $expectedSlotReference = is_string($safeArguments[$slotArgument] ?? null)
            ? $safeArguments[$slotArgument]
            : null;
        $availability = $this->checkAvailability(
            $bot,
            $runtimeOperation->attachment,
            $preflightArguments,
            expectedSlotReference: $expectedSlotReference,
        );

        if ($availability['failure'] instanceof ToolResult) {
            return $availability['failure'];
        }

        if ($availability['slot_reference'] === null) {
            return null;
        }

        $schema = $this->operationSchema($runtimeOperation);

        if ($schema === null || ! array_key_exists($slotArgument, $schema['properties'])) {
            return null;
        }

        $writeArguments = [];

        foreach ((array) $safeArguments as $key => $value) {
            if (is_string($key) && $key !== '__preflight') {
                $writeArguments[$key] = $value;
            }
        }

        $writeArguments[$slotArgument] = $availability['slot_reference'];
        $safeArguments = array_merge(
            $writeArguments,
            ['__preflight' => $preflightArguments],
        );
        $run->update([
            'safe_arguments' => $this->payloadSanitizer->sanitize($safeArguments),
        ]);

        return null;
    }

    private function availabilityIdentifier(BotApiOperation $attachment): ?string
    {
        $settings = $attachment->getAttribute('settings');
        $configured = is_array($settings) ? ($settings['availability_operation'] ?? null) : null;

        if ($configured === null) {
            return self::DEFAULT_AVAILABILITY_OPERATION;
        }

        if (! is_string($configured) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,99}$/', $configured) !== 1) {
            return null;
        }

        return $configured;
    }

    private function hasExplicitAvailabilityConfiguration(BotApiOperation $attachment): bool
    {
        $settings = $attachment->getAttribute('settings');

        return is_array($settings) && array_key_exists('availability_operation', $settings);
    }

    private function availabilityOutput(BotApiOperation $attachment, string $setting): string
    {
        $settings = $attachment->getAttribute('settings');
        $value = is_array($settings) ? ($settings[$setting] ?? null) : null;

        return is_string($value) && preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,99}$/', $value) === 1
            ? $value
            : ($setting === 'availability_output' ? 'available' : 'slot_reference');
    }

    private function slotArgument(BotApiOperation $attachment): string
    {
        $settings = $attachment->getAttribute('settings');
        $value = is_array($settings) ? ($settings['availability_slot_argument'] ?? null) : null;

        return is_string($value) && preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,99}$/', $value) === 1
            ? $value
            : 'slot_reference';
    }

    /**
     * @return array{properties: array<string, array<string, mixed>>, required: list<string>}|null
     */
    private function operationSchema(RuntimeApiOperation $runtimeOperation): ?array
    {
        return $this->operationSchemaFromModel($runtimeOperation->operation);
    }

    /**
     * @return array{properties: array<string, array<string, mixed>>, required: list<string>}|null
     */
    private function operationSchemaFromModel(ApiOperation $operation): ?array
    {
        $schema = $operation->getAttribute('request_schema');

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

        return ['properties' => $properties, 'required' => $required];
    }

    /**
     * @param  array<string, string>  $inputs
     */
    private function confirmationSummary(array $inputs): string
    {
        $date = CarbonImmutable::parse($inputs['start_at'] ?? 'now');
        $timezone = $inputs['timezone'] ?? $date->getTimezone()->getName();
        $summary = 'Book an appointment for '.$date->format('F j, Y \a\t g:i A').' '.$timezone;

        if (isset($inputs['service'])) {
            $summary .= ' for '.Str::limit($inputs['service'], 100, '');
        }

        if (isset($inputs['location'])) {
            $summary .= ' at '.Str::limit($inputs['location'], 100, '');
        }

        return $summary.'.';
    }

    private function invalidRequest(): ToolResult
    {
        return ToolResult::failure(
            'invalid_request',
            'The appointment request could not be fulfilled with the supplied details.',
        );
    }

    private function integrationUnavailable(): ToolResult
    {
        return ToolResult::failure(
            'integration_unavailable',
            'Appointment booking is not currently available.',
        );
    }
}
