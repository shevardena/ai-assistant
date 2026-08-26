<?php

namespace App\Services\Api;

use App\Enums\ApiOperationMode;
use App\Services\Api\Exceptions\GraphqlRequestException;
use App\Services\Imports\Exceptions\ImportException;
use App\Services\Imports\RestApiRequestExecutor;
use App\Services\Imports\SourcePathResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Throwable;

class RuntimeApiOperationExecutor
{
    private const MAX_MAPPED_COLLECTION_ITEMS = 20;

    /**
     * Names that must never be supplied as runtime business arguments.
     *
     * @var list<string>
     */
    private const RESERVED_ARGUMENTS = [
        'url',
        'endpoint',
        'host',
        'authorization',
        'headers',
        'method',
        'credential_id',
    ];

    public function __construct(
        private readonly RestApiRequestExecutor $requestExecutor,
        private readonly SourcePathResolver $sourcePathResolver,
        private readonly GraphqlRequestExecutor $graphqlRequestExecutor,
    ) {}

    /**
     * Execute a previously resolved, read-only operation.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function execute(RuntimeApiOperation $runtimeOperation, array $arguments): RuntimeApiResult
    {
        return $this->executeMode($runtimeOperation, $arguments, ApiOperationMode::Read, true);
    }

    /**
     * Execute a previously resolved write operation without connection retries.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function executeWrite(
        RuntimeApiOperation $runtimeOperation,
        array $arguments,
        ?string $idempotencyKey = null,
    ): RuntimeApiResult {
        return $this->executeMode($runtimeOperation, $arguments, ApiOperationMode::Write, false, $idempotencyKey);
    }

    /**
     * Validate a write operation without making an outbound request.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function validateWrite(RuntimeApiOperation $runtimeOperation, array $arguments): RuntimeApiResult
    {
        try {
            $this->assertUsable($runtimeOperation, ApiOperationMode::Write);
            $this->validateArguments($runtimeOperation, $arguments);
            if ($runtimeOperation->dataSource->type === 'graphql_api') {
                $this->graphqlRequestExecutor->buildRequest(
                    $runtimeOperation->operation,
                    $runtimeOperation->dataSource,
                    $arguments,
                );
            } else {
                $this->request($runtimeOperation, $arguments);
            }

            return RuntimeApiResult::success([], 200);
        } catch (InvalidArgumentException) {
            return RuntimeApiResult::failure(
                'invalid_request',
                'The integration request arguments are invalid.',
            );
        } catch (GraphqlRequestException $exception) {
            return RuntimeApiResult::failure($exception->errorType, 'The GraphQL operation is not configured correctly.');
        } catch (LogicException|ImportException) {
            return RuntimeApiResult::failure(
                'integration_error',
                'The integration operation is not configured correctly.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function executeMode(
        RuntimeApiOperation $runtimeOperation,
        array $arguments,
        ApiOperationMode $mode,
        bool $retryConnection,
        ?string $idempotencyKey = null,
    ): RuntimeApiResult {
        try {
            $this->assertUsable($runtimeOperation, $mode);
            $this->validateArguments($runtimeOperation, $arguments);
            $request = $runtimeOperation->dataSource->type === 'graphql_api'
                ? null
                : $this->request($runtimeOperation, $arguments);
            if ($runtimeOperation->dataSource->type === 'graphql_api') {
                $this->graphqlRequestExecutor->buildRequest(
                    $runtimeOperation->operation,
                    $runtimeOperation->dataSource,
                    $arguments,
                );
            }
        } catch (GraphqlRequestException $exception) {
            return RuntimeApiResult::failure($exception->errorType, 'The GraphQL operation is not configured correctly.');
        } catch (InvalidArgumentException) {
            return RuntimeApiResult::failure(
                'invalid_request',
                'The integration request arguments are invalid.',
            );
        } catch (LogicException|ImportException) {
            return RuntimeApiResult::failure(
                'integration_error',
                'The integration operation is not configured correctly.',
            );
        }

        try {
            $response = $runtimeOperation->dataSource->type === 'graphql_api'
                ? $this->graphqlRequestExecutor->execute(
                    $runtimeOperation->operation,
                    $runtimeOperation->dataSource,
                    $arguments,
                    retryConnection: $retryConnection,
                    idempotencyKey: $idempotencyKey,
                )
                : $this->requestExecutor->executeRequest(
                    $runtimeOperation->operation,
                    $runtimeOperation->dataSource,
                    $request['method'],
                    $request['url'],
                    $request['query'],
                    $request['body'],
                    retryConnection: $retryConnection,
                    idempotencyKey: $idempotencyKey,
                );

            return RuntimeApiResult::success(
                $this->mapResponse($runtimeOperation, $response['data']),
                $response['status'],
            );
        } catch (GraphqlRequestException $exception) {
            return RuntimeApiResult::failure($exception->errorType, 'The GraphQL operation could not be completed safely.');
        } catch (InvalidArgumentException|LogicException) {
            return RuntimeApiResult::failure(
                'integration_error',
                'The integration response could not be normalized safely.',
            );
        } catch (ConnectionException $exception) {
            logger()->warning('Runtime API operation connection failed.', [
                'bot_id' => $runtimeOperation->bot->id,
                'team_id' => $runtimeOperation->bot->team_id,
                'operation' => $runtimeOperation->operation->key,
                'exception' => $exception::class,
            ]);

            $error = Str::contains(Str::lower($exception->getMessage()), ['timeout', 'timed out'])
                ? 'timeout'
                : 'unavailable';

            return RuntimeApiResult::failure(
                $error,
                'The integration is temporarily unavailable.',
            );
        } catch (ImportException $exception) {
            logger()->warning('Runtime API operation failed.', [
                'bot_id' => $runtimeOperation->bot->id,
                'team_id' => $runtimeOperation->bot->team_id,
                'operation' => $runtimeOperation->operation->key,
                'exception' => $exception::class,
            ]);

            return RuntimeApiResult::failure(
                'integration_error',
                'The integration request could not be completed safely.',
            );
        } catch (Throwable $exception) {
            logger()->warning('Runtime API operation encountered an unexpected failure.', [
                'bot_id' => $runtimeOperation->bot->id,
                'team_id' => $runtimeOperation->bot->team_id,
                'operation' => $runtimeOperation->operation->key,
                'exception' => $exception::class,
            ]);

            return RuntimeApiResult::failure(
                'integration_error',
                'The integration request could not be completed safely.',
            );
        }
    }

    private function assertUsable(RuntimeApiOperation $runtimeOperation, ApiOperationMode $expectedMode): void
    {
        $operation = $runtimeOperation->operation;
        $dataSource = $runtimeOperation->dataSource;

        if ((int) $runtimeOperation->attachment->bot_id !== (int) $runtimeOperation->bot->id
            || ! $runtimeOperation->attachment->is_enabled
            || (int) $operation->data_source_id !== (int) $dataSource->id
            || (int) $dataSource->team_id !== (int) $runtimeOperation->bot->team_id
            || ! $operation->is_enabled
            || $this->operationMode($operation->getAttribute('execution_mode')) !== $expectedMode
            || ! in_array($dataSource->type, ['rest_api', 'graphql_api'], true)) {
            throw new LogicException('The runtime API operation is not usable.');
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function validateArguments(RuntimeApiOperation $runtimeOperation, array $arguments): void
    {
        $schema = $runtimeOperation->operation->getAttribute('request_schema');

        if ($schema === null) {
            $schema = [];
        }

        if (! is_array($schema)) {
            throw new LogicException('The request schema is invalid.');
        }

        $properties = $schema['properties'] ?? [];

        if (! is_array($properties)) {
            throw new LogicException('The request schema properties are invalid.');
        }

        foreach ($arguments as $name => $value) {
            if ($name === '__context') {
                if (! is_array($value)) {
                    throw new InvalidArgumentException('The runtime context is invalid.');
                }

                continue;
            }

            if (in_array(Str::lower($name), self::RESERVED_ARGUMENTS, true)
                || ! array_key_exists($name, $properties)
                || ! is_array($properties[$name])) {
                throw new InvalidArgumentException('The runtime argument is not configured.');
            }

            $this->validateArgumentType($value, $properties[$name]);
        }

        foreach ((array) ($schema['required'] ?? []) as $name) {
            if (! is_string($name) || ! array_key_exists($name, $arguments)) {
                throw new InvalidArgumentException('A required runtime argument is missing.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function validateArgumentType(mixed $value, array $definition): void
    {
        $type = $definition['type'] ?? null;

        if (! is_string($type)) {
            throw new LogicException('The runtime argument type is invalid.');
        }

        $valid = match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'array' => is_array($value),
            'object' => is_array($value),
            'null' => $value === null,
            default => throw new LogicException('The runtime argument type is unsupported.'),
        };

        if (! $valid) {
            throw new InvalidArgumentException('The runtime argument type is invalid.');
        }

        if (is_string($value)) {
            if (mb_strlen($value) > 1000 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new InvalidArgumentException('The runtime argument string is invalid.');
            }

            if (array_key_exists('minLength', $definition) && mb_strlen($value) < (int) $definition['minLength']) {
                throw new InvalidArgumentException('The runtime argument string is too short.');
            }

            if (array_key_exists('maxLength', $definition) && mb_strlen($value) > (int) $definition['maxLength']) {
                throw new InvalidArgumentException('The runtime argument string is too long.');
            }
        }

        if (array_key_exists('enum', $definition)
            && is_array($definition['enum'])
            && ! in_array($value, $definition['enum'], true)) {
            throw new InvalidArgumentException('The runtime argument is not an allowed value.');
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{method: string, url: string, query: array<string, mixed>, body: array<string, mixed>}
     */
    private function request(RuntimeApiOperation $runtimeOperation, array $arguments): array
    {
        $operation = $runtimeOperation->operation;
        $path = $operation->getAttribute('path');
        $mapping = $operation->getAttribute('request_mapping');

        if (! is_string($path) || ! is_array($mapping)) {
            throw new LogicException('The request configuration is invalid.');
        }

        $pathMapping = $this->mappingSection($mapping, 'path');
        $pathPlaceholders = $this->placeholders($path);

        foreach ($pathMapping as $argumentName => $placeholder) {
            if (! is_string($placeholder) || ! array_key_exists($argumentName, $arguments)) {
                throw new LogicException('The path mapping is invalid.');
            }

            $placeholder = trim($placeholder, '{}');

            if (! in_array($placeholder, $pathPlaceholders, true)) {
                throw new LogicException('The path mapping does not match the operation path.');
            }

            $path = str_replace(
                '{'.$placeholder.'}',
                rawurlencode((string) $this->scalarValue($arguments[$argumentName])),
                $path,
            );
        }

        foreach ($pathPlaceholders as $placeholder) {
            if (Str::contains($path, '{'.$placeholder.'}')) {
                if (! array_key_exists($placeholder, $arguments)) {
                    throw new InvalidArgumentException('A path argument is missing.');
                }

                $path = str_replace(
                    '{'.$placeholder.'}',
                    rawurlencode((string) $this->scalarValue($arguments[$placeholder])),
                    $path,
                );
            }
        }

        if (preg_match('/[{}]/', $path) === 1) {
            throw new LogicException('The operation path contains an unresolved parameter.');
        }

        $query = $this->fixedParameterMapping($mapping, 'query');
        $query = [...$query, ...$this->parameterMapping($mapping, 'query', $arguments)];
        $body = array_replace_recursive(
            $this->fixedParameterMapping($mapping, 'body', nested: true),
            $this->parameterMapping($mapping, 'body', $arguments, true),
        );
        $method = Str::upper((string) $operation->getAttribute('method'));

        if ($body !== [] && in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            throw new LogicException('The request body is not compatible with the operation method.');
        }

        return [
            'method' => $method,
            'url' => $this->requestExecutor->operationUrl($runtimeOperation->dataSource, $operation, $path),
            'query' => $query,
            'body' => $body,
        ];
    }

    /**
     * @param  array<string, mixed>  $mapping
     * @return array<int|string, mixed>
     */
    private function mappingSection(array $mapping, string $section): array
    {
        $value = $mapping[$section] ?? [];

        if (! is_array($value)) {
            throw new LogicException('The request mapping section is invalid.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $mapping
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function parameterMapping(array $mapping, string $section, array $arguments, bool $nested = false): array
    {
        $sectionMapping = $this->mappingSection($mapping, $section);
        $values = [];

        foreach ($sectionMapping as $argumentName => $destination) {
            if (! is_string($destination)
                || $destination === ''
                || preg_match('/[\x00-\x1F\x7F]/', $destination) === 1) {
                throw new LogicException('The request parameter mapping is invalid.');
            }

            if (! array_key_exists($argumentName, $arguments)) {
                continue;
            }

            $value = $this->scalarValue($arguments[$argumentName]);

            if ($nested) {
                if (Str::startsWith($destination, '.') || Str::contains($destination, '..')) {
                    throw new LogicException('The request body mapping is invalid.');
                }

                Arr::set($values, $destination, $value);
            } else {
                $values[$destination] = $value;
            }
        }

        return $values;
    }

    /**
     * Resolve fixed builder values without exposing them as model arguments.
     *
     * @param  array<string, mixed>  $mapping
     * @return array<string, mixed>
     */
    private function fixedParameterMapping(array $mapping, string $section, bool $nested = false): array
    {
        $fixed = $mapping['fixed'][$section] ?? [];

        if (! is_array($fixed)) {
            throw new LogicException('The fixed request mapping is invalid.');
        }

        $values = [];

        foreach ($fixed as $destination => $value) {
            if (! is_string($destination)
                || $destination === ''
                || (! is_scalar($value) && $value !== null)
                || preg_match('/[\x00-\x1F\x7F]/', $destination) === 1) {
                throw new LogicException('The fixed request mapping is invalid.');
            }

            if ($nested) {
                if (Str::startsWith($destination, '.') || Str::contains($destination, '..')) {
                    throw new LogicException('The fixed request body mapping is invalid.');
                }

                Arr::set($values, $destination, $value);
            } else {
                $values[$destination] = $value;
            }
        }

        return $values;
    }

    /**
     * @return list<string>
     */
    private function placeholders(string $path): array
    {
        preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $path, $matches);

        return array_values(array_unique(array_map('strval', $matches[1])));
    }

    private function scalarValue(mixed $value): string|int|float|bool|null
    {
        if (! is_scalar($value) && $value !== null) {
            throw new InvalidArgumentException('Only scalar runtime parameters are supported.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<int|string, mixed>
     */
    private function mapResponse(RuntimeApiOperation $runtimeOperation, array $response): array
    {
        $mapping = $runtimeOperation->operation->getAttribute('response_mapping');

        if (is_array($mapping) && array_key_exists('collection', $mapping)) {
            if (array_key_exists('output', $mapping) || array_key_exists('fields', $mapping)) {
                throw new LogicException('The response mapping cannot mix collection and scalar output.');
            }

            return $this->mapCollection($mapping['collection'], $response);
        }

        $output = is_array($mapping) ? ($mapping['output'] ?? $mapping['fields'] ?? null) : null;

        if (! is_array($output) || $output === []) {
            throw new LogicException('The response output mapping is missing.');
        }

        $result = [];

        foreach ($output as $name => $definition) {
            if (! is_string($name)
                || preg_match('/(?:authorization|credential|password|secret|token)/i', $name) === 1) {
                throw new LogicException('The response output mapping contains a protected field.');
            }

            [$path, $required] = $this->responseDefinition($definition);

            if (preg_match('/(?:authorization|credential|password|secret|token)/i', $path) === 1) {
                throw new LogicException('The response output mapping contains a protected path.');
            }

            $value = $this->sourcePathResolver->get($response, $path);

            if ($required && $value === null) {
                throw new LogicException('A required response field is missing.');
            }

            if (! $this->isSafeOutputValue($value)) {
                throw new LogicException('The response output contains an unsafe value.');
            }

            $result[$name] = $value;
        }

        return $result;
    }

    private function isSafeOutputValue(mixed $value): bool
    {
        if (is_scalar($value) || $value === null) {
            return true;
        }

        if (! is_array($value)
            || ! array_is_list($value)
            || count($value) > self::MAX_MAPPED_COLLECTION_ITEMS) {
            return false;
        }

        foreach ($value as $item) {
            if (! is_scalar($item) && $item !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Map a bounded collection through explicitly configured scalar fields.
     *
     * @param  array<string, mixed>  $response
     * @return list<array<string, scalar|null>>
     */
    private function mapCollection(mixed $definition, array $response): array
    {
        if (! is_array($definition)
            || ! is_string($definition['path'] ?? null)
            || $definition['path'] === ''
            || ! is_array($definition['fields'] ?? null)
            || $definition['fields'] === []) {
            throw new LogicException('The response collection mapping is invalid.');
        }

        $configuredLimit = $definition['limit'] ?? self::MAX_MAPPED_COLLECTION_ITEMS;

        if (! is_int($configuredLimit) || $configuredLimit < 1) {
            throw new LogicException('The response collection limit is invalid.');
        }

        $limit = min($configuredLimit, self::MAX_MAPPED_COLLECTION_ITEMS);
        $collection = $this->sourcePathResolver->get($response, $definition['path']);

        if ($collection === null) {
            return [];
        }

        if (! is_array($collection)) {
            throw new LogicException('The response collection is not an array.');
        }

        $result = [];

        foreach (array_slice($collection, 0, $limit) as $item) {
            if (! is_array($item)) {
                throw new LogicException('The response collection item is unsafe.');
            }

            $mappedItem = [];

            foreach ($definition['fields'] as $name => $fieldDefinition) {
                if (! is_string($name)
                    || preg_match('/(?:authorization|credential|password|secret|token)/i', $name) === 1) {
                    throw new LogicException('The response collection field is protected.');
                }

                [$path, $required] = $this->responseDefinition($fieldDefinition);

                if (preg_match('/(?:authorization|credential|password|secret|token)/i', $path) === 1) {
                    throw new LogicException('The response collection path is protected.');
                }

                $value = $this->sourcePathResolver->get($item, $path);

                if ($required && $value === null) {
                    throw new LogicException('A required response collection field is missing.');
                }

                if (! is_scalar($value) && $value !== null) {
                    throw new LogicException('The response collection field is unsafe.');
                }

                $this->validateMappedCoordinate($name, $value);
                $mappedItem[$name] = $value;
            }

            $result[] = $mappedItem;
        }

        return $result;
    }

    private function validateMappedCoordinate(string $name, mixed $value): void
    {
        if ($value === null || ! in_array(Str::lower($name), ['latitude', 'longitude'], true)) {
            return;
        }

        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            throw new LogicException('The mapped coordinate is invalid.');
        }

        if (! is_numeric($value)) {
            throw new LogicException('The mapped coordinate is invalid.');
        }

        $numericValue = (float) $value;
        $maximum = Str::lower($name) === 'latitude' ? 90 : 180;

        if ($numericValue < -$maximum || $numericValue > $maximum) {
            throw new LogicException('The mapped coordinate is out of range.');
        }
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private function responseDefinition(mixed $definition): array
    {
        if (is_string($definition) && $definition !== '') {
            return [$definition, true];
        }

        if (is_array($definition)
            && is_string($definition['path'] ?? null)
            && $definition['path'] !== '') {
            return [$definition['path'], (bool) ($definition['required'] ?? true)];
        }

        throw new LogicException('The response output mapping is invalid.');
    }

    private function operationMode(mixed $mode): ?ApiOperationMode
    {
        return $mode instanceof ApiOperationMode
            ? $mode
            : (is_string($mode) ? ApiOperationMode::tryFrom($mode) : null);
    }
}
