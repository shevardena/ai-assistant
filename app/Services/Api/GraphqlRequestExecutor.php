<?php

namespace App\Services\Api;

use App\Enums\ApiOperationMode;
use App\Models\ApiOperation;
use App\Models\DataSource;
use App\Services\Api\Exceptions\GraphqlRequestException;
use App\Services\Imports\Exceptions\ImportException;
use App\Services\Imports\RestApiRequestExecutor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;

final class GraphqlRequestExecutor
{
    public function __construct(
        private readonly RestApiRequestExecutor $http,
        private readonly GraphqlDocumentInspector $documents,
    ) {}

    /**
     * Build a bounded GraphQL request from the operation's trusted mapping.
     *
     * @param  array<string, mixed>  $arguments
     * @return array{query: string, variables: array<string, mixed>, operationName: ?string, operationType: string}
     */
    public function buildRequest(ApiOperation $operation, DataSource $dataSource, array $arguments = []): array
    {
        $this->assertDataSource($dataSource);
        $mapping = $this->mapping($operation);
        $graphql = $mapping['graphql'] ?? [];

        if (! is_array($graphql) || ! is_string($graphql['document'] ?? null)) {
            throw new GraphqlRequestException('graphql_query_invalid', 'The GraphQL operation document is not configured.');
        }

        $operationName = is_string($graphql['operation_name'] ?? null) && $graphql['operation_name'] !== ''
            ? $graphql['operation_name']
            : null;
        $definition = $this->documents->inspect($graphql['document'], $operationName);
        $expectedType = $operation->execution_mode === ApiOperationMode::Write->value ? 'mutation' : 'query';

        if ($definition['operation_type'] !== $expectedType) {
            throw new GraphqlRequestException(
                'graphql_query_invalid',
                "GraphQL {$expectedType} operations must use a {$expectedType} document.",
            );
        }

        $variables = [
            ...$this->defaultVariables($dataSource),
            ...$this->variables($graphql['variables'] ?? [], $arguments),
        ];

        foreach ($definition['variables'] as $variable) {
            if ($variable['required']
                && (! array_key_exists($variable['name'], $variables) || $variables[$variable['name']] === null)) {
                throw new GraphqlRequestException(
                    'graphql_query_invalid',
                    "The required GraphQL variable [{$variable['name']}] is not configured.",
                );
            }
        }

        return [
            'query' => $graphql['document'],
            'variables' => $variables,
            'operationName' => $definition['operation_name'],
            'operationType' => $definition['operation_type'],
        ];
    }

    /**
     * Execute a GraphQL request and reject HTTP-200 responses containing errors.
     *
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $variableOverrides
     * @return array{data: array<string, mixed>, status: int, url: string, operationName: ?string}
     */
    public function execute(
        ApiOperation $operation,
        DataSource $dataSource,
        array $arguments = [],
        bool $retryConnection = true,
        ?string $idempotencyKey = null,
        array $variableOverrides = [],
    ): array {
        $request = $this->buildRequest($operation, $dataSource, $arguments);

        foreach ($variableOverrides as $variableName => $value) {
            if (! array_key_exists($variableName, $request['variables'])) {
                throw new GraphqlRequestException('graphql_query_invalid', 'The GraphQL cursor variable is not declared by the operation.');
            }
        }

        $request['variables'] = [...$request['variables'], ...$variableOverrides];
        $endpoint = $this->endpoint($dataSource);
        try {
            $response = $this->http->executeJsonPayload(
                $operation,
                $dataSource,
                $endpoint,
                [
                    'query' => $request['query'],
                    'variables' => $request['variables'],
                    'operationName' => $request['operationName'],
                ],
                retryConnection: $retryConnection,
                idempotencyKey: $idempotencyKey,
            );
        } catch (ConnectionException $exception) {
            $timeout = str_contains(strtolower($exception->getMessage()), 'timeout');

            throw new GraphqlRequestException(
                $timeout ? 'graphql_timeout' : 'graphql_provider_unavailable',
                'The GraphQL provider could not be reached.',
                $exception,
            );
        } catch (ImportException $exception) {
            $message = $exception->getMessage();
            if (str_contains($message, 'HTTP 401') || str_contains($message, 'HTTP 403')) {
                $errorType = 'graphql_auth_failed';
            } elseif (str_contains($message, 'HTTP 429')) {
                $errorType = 'graphql_rate_limited';
            } else {
                $errorType = 'graphql_provider_unavailable';
            }

            throw new GraphqlRequestException($errorType, 'The GraphQL provider rejected the request.', $exception);
        }
        $envelope = $response['data'];

        if (array_key_exists('errors', $envelope) && $envelope['errors'] !== []) {
            throw new GraphqlRequestException(
                'graphql_execution_failed',
                'The GraphQL provider returned execution errors.',
            );
        }

        if (! array_key_exists('data', $envelope) || ! is_array($envelope['data'])) {
            throw new GraphqlRequestException(
                'graphql_response_invalid',
                'The GraphQL response did not contain a valid data object.',
            );
        }

        return [
            'data' => $envelope['data'],
            'status' => $response['status'],
            'url' => $response['url'],
            'operationName' => $request['operationName'],
        ];
    }

    /**
     * @param  array<string, mixed>  $definitions
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function variables(array $definitions, array $arguments): array
    {
        $variables = [];

        foreach ($definitions as $variableName => $definition) {
            if (! is_array($definition)) {
                throw new GraphqlRequestException('graphql_query_invalid', 'The GraphQL variable mapping is invalid.');
            }

            $source = (string) ($definition['source'] ?? 'tool_argument');

            if ($source === 'fixed') {
                $variables[$variableName] = $definition['value'] ?? null;

                continue;
            }

            $argument = (string) ($definition['argument'] ?? $variableName);

            if ($source === 'context') {
                $context = is_array($arguments['__context'] ?? null) ? $arguments['__context'] : [];

                if (array_key_exists((string) ($definition['context_key'] ?? ''), $context)) {
                    $variables[$variableName] = $context[(string) $definition['context_key']];
                }

                continue;
            }

            if ($source !== 'tool_argument' || ! array_key_exists($argument, $arguments)) {
                continue;
            }

            $variables[$variableName] = $arguments[$argument];
        }

        return $variables;
    }

    /** @return array<string, mixed> */
    private function defaultVariables(DataSource $dataSource): array
    {
        $variables = Arr::get((array) $dataSource->config, 'default_variables', []);

        if (! is_array($variables)) {
            return [];
        }

        return array_filter(
            $variables,
            static fn (mixed $value, string|int $key): bool => is_string($key) && (is_scalar($value) || $value === null),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /** @return array<int|string, mixed> */
    private function mapping(ApiOperation $operation): array
    {
        $mapping = $operation->request_mapping;

        return (array) $mapping;
    }

    private function endpoint(DataSource $dataSource): string
    {
        $endpoint = Arr::get((array) $dataSource->config, 'endpoint');

        if (! is_string($endpoint) || $endpoint === '') {
            throw new GraphqlRequestException('graphql_query_invalid', 'Configure a GraphQL endpoint first.');
        }

        $this->http->assertSafeUrl($endpoint);

        return $endpoint;
    }

    private function assertDataSource(DataSource $dataSource): void
    {
        if ($dataSource->type !== 'graphql_api') {
            throw new GraphqlRequestException('graphql_query_invalid', 'Only GraphQL API data sources can execute GraphQL operations.');
        }
    }
}
