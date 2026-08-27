<?php

namespace App\Services\Api;

use App\Models\ApiOperation;
use App\Models\DataSource;
use App\Services\Api\Exceptions\GraphqlRequestException;
use App\Services\Imports\Exceptions\ImportException;
use App\Services\Imports\RestApiRequestExecutor;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class ApiConnectionBuilderService
{
    public function __construct(
        private readonly RestApiRequestExecutor $requestExecutor,
        private readonly ApiResponseInspector $inspector,
        private readonly GraphqlDocumentInspector $graphqlDocuments,
        private readonly GraphqlRequestExecutor $graphqlRequestExecutor,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{config: array<string, mixed>, credentials: array<string, string>}
     */
    public function connectionValues(array $input, ?DataSource $existing = null): array
    {
        $config = (array) $existing?->config;
        $submittedConfig = is_array($input['advanced_config'] ?? null) ? $input['advanced_config'] : [];

        $config = [
            ...$config,
            ...$submittedConfig,
            'base_url' => rtrim((string) ($input['base_url'] ?? Arr::get($config, 'base_url', '')), '/'),
            'auth_type' => (string) ($input['auth_type'] ?? Arr::get($config, 'auth_type', 'none')),
            'default_headers' => $this->keyValueObject($input['default_headers'] ?? Arr::get($config, 'default_headers', [])),
            'default_query_parameters' => $this->keyValueObject($input['default_query_parameters'] ?? Arr::get($config, 'default_query_parameters', [])),
            'default_variables' => $this->keyValueObject($input['default_variables'] ?? Arr::get($config, 'default_variables', [])),
        ];

        foreach (['api_key_placement', 'api_key_name', 'custom_header_name'] as $key) {
            if (array_key_exists($key, $input) && $input[$key] !== null && $input[$key] !== '') {
                $config[$key] = $input[$key];
            }
        }

        $credentials = [];
        $credentialMap = match ($config['auth_type']) {
            'bearer' => ['bearer_token' => 'bearer_token'],
            'api_key' => ['api_key' => 'api_key'],
            'basic' => [
                'basic_username' => 'basic_username',
                'basic_password' => 'basic_password',
            ],
            'custom_header' => ['custom_header_value' => 'custom_header_value'],
            default => [],
        };

        foreach ($credentialMap as $inputKey => $credentialKey) {
            $value = $input[$inputKey] ?? null;

            if (is_string($value) && $value !== '') {
                $credentials[$credentialKey] = $value;
            }
        }

        return [
            'config' => $config,
            'credentials' => $credentials,
        ];
    }

    /**
     * Convert the shared authentication form into a GraphQL endpoint config.
     *
     * @param  array<string, mixed>  $input
     * @return array{config: array<string, mixed>, credentials: array<string, string>}
     */
    public function graphqlConnectionValues(array $input, ?DataSource $existing = null): array
    {
        $endpoint = (string) ($input['endpoint'] ?? Arr::get((array) $existing?->config, 'endpoint', ''));
        $values = $this->connectionValues([
            ...$input,
            'base_url' => $endpoint,
        ], $existing);

        $values['config']['protocol'] = 'graphql';
        $values['config']['endpoint'] = rtrim($endpoint, '/');
        unset($values['config']['base_url']);

        return $values;
    }

    /**
     * Convert a GraphQL operation form into the existing ApiOperation schema.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function graphqlOperationValues(array $input): array
    {
        $document = (string) ($input['graphql_document'] ?? '');
        $operationName = is_string($input['graphql_operation_name'] ?? null) && $input['graphql_operation_name'] !== ''
            ? $input['graphql_operation_name']
            : null;
        $definition = $this->graphqlDocuments->inspect($document, $operationName);
        $usage = $this->mode((string) ($input['usage'] ?? 'live_read'));

        if ($definition['operation_type'] === 'mutation' && $usage !== 'live_write') {
            throw new GraphqlRequestException('graphql_query_invalid', 'GraphQL mutations must use live write mode.');
        }

        if ($definition['operation_type'] === 'query' && $usage === 'live_write') {
            throw new GraphqlRequestException('graphql_query_invalid', 'GraphQL queries cannot use live write mode.');
        }

        $variables = [];
        $properties = [];
        $required = [];
        $rows = is_array($input['graphql_variables'] ?? null) ? $input['graphql_variables'] : [];
        $rowsByName = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['name'] ?? null) || $row['name'] === '') {
                continue;
            }

            $rowsByName[$row['name']] = $row;
        }

        foreach ($definition['variables'] as $variable) {
            $name = $variable['name'];
            $row = $rowsByName[$name] ?? [
                'source' => 'tool_argument',
                'argument' => $name,
            ];
            $source = (string) ($row['source'] ?? 'tool_argument');

            if (! in_array($source, ['fixed', 'tool_argument', 'context'], true)) {
                throw new GraphqlRequestException('graphql_query_invalid', 'The GraphQL variable source is invalid.');
            }

            $definitionValues = [
                'source' => $source,
            ];

            if ($source === 'fixed') {
                $definitionValues['value'] = $this->graphqlFixedValue(
                    $variable['type'],
                    $row['value'] ?? null,
                );
            } elseif ($source === 'context') {
                $definitionValues['context_key'] = (string) ($row['context_key'] ?? '');
            } else {
                $argument = (string) ($row['argument'] ?? $name);
                $definitionValues['argument'] = $argument;
                $properties[$argument] = $this->graphqlSchemaType($variable['type']);

                if ($variable['required']) {
                    $required[] = $argument;
                }
            }

            $variables[$name] = $definitionValues;
        }

        $responseMapping = is_array($input['response_mapping'] ?? null) ? $input['response_mapping'] : [];

        if ($usage === 'synced') {
            $responseMapping = [
                ...$responseMapping,
                'records_path' => (string) ($input['records_path'] ?? Arr::get($responseMapping, 'records_path', '')),
                'sync_mode' => 'full_snapshot',
            ];
        } else {
            $responseMapping = $this->liveResponseMapping($responseMapping, $input['response_fields'] ?? []);
        }

        if (is_array($input['pagination'] ?? null)) {
            $responseMapping['pagination'] = $input['pagination'];
        }

        return [
            'key' => (string) ($input['key'] ?? Str::slug((string) ($input['name'] ?? 'graphql-operation'))),
            'name' => (string) ($input['name'] ?? 'GraphQL operation'),
            'type' => $definition['operation_type'] === 'mutation' ? 'action' : ($usage === 'synced' ? 'query' : 'read'),
            'execution_mode' => $usage === 'live_write' ? 'write' : 'read',
            'method' => 'POST',
            'path' => '/',
            'request_schema' => [
                'type' => 'object',
                'properties' => $properties,
                'required' => array_values(array_unique($required)),
            ],
            'request_mapping' => [
                'graphql' => [
                    'document' => $document,
                    'operation_name' => $definition['operation_name'],
                    'variables' => $variables,
                ],
            ],
            'response_mapping' => $responseMapping,
            'headers' => $this->keyValueObject($input['headers'] ?? []),
            'timeout_ms' => (int) ($input['timeout_ms'] ?? 10000),
            'is_enabled' => (bool) ($input['is_enabled'] ?? true),
        ];
    }

    /**
     * Test a GraphQL query or return a dry-run mutation preview.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function testGraphqlOperation(DataSource $dataSource, array $input): array
    {
        $values = $this->graphqlOperationValues($input);
        $operation = new ApiOperation([
            ...$values,
            'data_source_id' => $dataSource->id,
        ]);
        $arguments = is_array($input['test_arguments'] ?? null) ? $input['test_arguments'] : [];
        $request = $this->graphqlRequestExecutor->buildRequest($operation, $dataSource, $arguments);

        if ($values['execution_mode'] === 'write') {
            return [
                'dryRun' => true,
                'status' => null,
                'url' => $this->safeUrl((string) Arr::get((array) $dataSource->config, 'endpoint', '')),
                'method' => 'POST',
                'operationName' => $request['operationName'],
                'variables' => $this->redact($request['variables']),
                'query' => $this->redact(['query' => $request['query']]),
                'message' => 'GraphQL mutations are previewed during setup and are not sent.',
            ];
        }

        $response = $this->graphqlRequestExecutor->execute($operation, $dataSource, $arguments, retryConnection: false);

        return [
            ...$this->preview($response['data'], $response['status'], $response['url']),
            'method' => 'POST',
            'operationName' => $response['operationName'],
        ];
    }

    /**
     * Test a bounded read request without persisting submitted credentials.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function test(array $input): array
    {
        $values = $this->connectionValues($input);
        $dataSource = new DataSource([
            'type' => 'rest_api',
            'config' => $values['config'],
        ]);
        $operation = new ApiOperation([
            'method' => 'GET',
            'path' => $this->path((string) ($input['path'] ?? '/')),
            'headers' => $this->keyValueObject($input['headers'] ?? []),
            'request_mapping' => [],
            'timeout_ms' => 10000,
        ]);

        $url = $this->url($values['config'], $operation->path);

        try {
            $response = $this->requestExecutor->executeRequest(
                $operation,
                $dataSource,
                'GET',
                $url,
                $this->keyValueObject($input['query_parameters'] ?? []),
                [],
                retryConnection: false,
                credentialOverrides: $values['credentials'],
            );
        } catch (ImportException $exception) {
            if (! Str::contains($exception->getMessage(), 'HTTP 404')) {
                throw $exception;
            }

            return [
                'status' => 404,
                'url' => $this->safeUrl($url),
                'contentType' => null,
                'response' => [],
                'recordArrays' => [],
                'fields' => [],
                'message' => 'The base URL is reachable, but its root endpoint returned 404. Test a specific operation path next.',
            ];
        }

        return $this->preview($response['data'], $response['status'], $response['url']);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    public function preview(array $response, int $status, string $url): array
    {
        $inspection = $this->inspector->inspect($response);

        return [
            'status' => $status,
            'url' => $this->safeUrl($url),
            'contentType' => 'application/json',
            'response' => $this->redact($this->bounded($response)),
            'recordArrays' => $inspection['recordArrays'],
            'fields' => $inspection['fields'],
        ];
    }

    /**
     * Convert builder rows into the existing API operation schema.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function operationValues(array $input): array
    {
        $mode = $this->mode((string) ($input['usage'] ?? $input['execution_mode'] ?? 'live_read'));
        $method = Str::upper((string) ($input['method'] ?? 'GET'));
        $path = $this->path((string) ($input['path'] ?? '/'));
        $properties = [];
        $required = [];
        $requestMapping = ['path' => [], 'query' => [], 'body' => [], 'fixed' => ['query' => [], 'body' => []]];

        foreach ($this->placeholders($path) as $placeholder) {
            $properties[$placeholder] = ['type' => 'string'];
            $required[] = $placeholder;
            $requestMapping['path'][$placeholder] = $placeholder;
        }

        foreach ($this->parameterRows($input['query_parameters'] ?? []) as $row) {
            $this->addParameter($row, $properties, $required, $requestMapping, 'query');
        }

        foreach ($this->parameterRows($input['body_parameters'] ?? []) as $row) {
            $this->addParameter($row, $properties, $required, $requestMapping, 'body');
        }

        if (is_array($input['live_query'] ?? null)) {
            $liveQuery = $input['live_query'];
            $searchText = trim((string) ($liveQuery['search_text'] ?? ''));
            if ($searchText !== '') {
                $requestMapping['live_query']['search_text'] = $searchText;
            }

            foreach ((array) ($liveQuery['filters'] ?? []) as $filter) {
                if (! is_array($filter)) {
                    continue;
                }
                $field = (string) ($filter['field'] ?? '');
                $operator = (string) ($filter['operator'] ?? '');
                $remote = trim((string) ($filter['remote'] ?? ''));
                if ($field !== '' && $operator !== '' && $remote !== '') {
                    $requestMapping['live_query']['filters'][$field][$operator] = $remote;
                }
            }
        }

        $responseMapping = is_array($input['response_mapping'] ?? null) ? $input['response_mapping'] : [];

        if ($mode === 'synced') {
            $responseMapping = [
                ...$responseMapping,
                'records_path' => (string) ($input['records_path'] ?? Arr::get($responseMapping, 'records_path', 'root')),
                'sync_mode' => 'full_snapshot',
            ];
        } else {
            $responseMapping = $this->liveResponseMapping($responseMapping, $input['response_fields'] ?? []);
        }

        if (is_array($input['pagination'] ?? null)) {
            $responseMapping['pagination'] = $input['pagination'];
        }

        return [
            'key' => (string) ($input['key'] ?? Str::slug((string) ($input['name'] ?? 'api-operation'))),
            'name' => (string) ($input['name'] ?? 'API operation'),
            'type' => $mode === 'synced' ? 'query' : ($mode === 'live_write' ? 'action' : 'read'),
            'execution_mode' => $mode === 'live_write' ? 'write' : 'read',
            'method' => $method,
            'path' => $path,
            'request_schema' => ['type' => 'object', 'properties' => $properties, 'required' => array_values(array_unique($required))],
            'request_mapping' => $requestMapping,
            'response_mapping' => $responseMapping,
            'headers' => $this->keyValueObject($input['headers'] ?? []),
            'timeout_ms' => (int) ($input['timeout_ms'] ?? 10000),
            'is_enabled' => (bool) ($input['is_enabled'] ?? true),
        ];
    }

    /**
     * Test a configured read operation, or return a dry-run preview for writes.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function testOperation(DataSource $dataSource, array $input): array
    {
        $values = $this->operationValues($input);
        $arguments = is_array($input['test_arguments'] ?? null) ? $input['test_arguments'] : [];
        $path = $values['path'];

        foreach ($this->placeholders($path) as $placeholder) {
            if (! array_key_exists($placeholder, $arguments) || ! is_scalar($arguments[$placeholder])) {
                throw new \InvalidArgumentException("Provide a test value for {$placeholder}.");
            }

            $path = str_replace('{'.$placeholder.'}', rawurlencode((string) $arguments[$placeholder]), $path);
        }

        $mapping = $values['request_mapping'];
        $query = $this->fixedValues($mapping, 'query');
        $body = $this->fixedValues($mapping, 'body', true);

        foreach ((array) ($mapping['query'] ?? []) as $argument => $destination) {
            if (array_key_exists($argument, $arguments) && is_scalar($arguments[$argument])) {
                $query[$destination] = $arguments[$argument];
            }
        }

        foreach ((array) ($mapping['body'] ?? []) as $argument => $destination) {
            if (array_key_exists($argument, $arguments) && is_scalar($arguments[$argument])) {
                Arr::set($body, $destination, $arguments[$argument]);
            }
        }

        $method = (string) $values['method'];
        $url = $this->url((array) $dataSource->config, $path);

        if ($values['execution_mode'] === 'write') {
            return [
                'dryRun' => true,
                'status' => null,
                'url' => $this->safeUrl($url),
                'method' => $method,
                'query' => $this->redact($query),
                'body' => $this->redact($body),
                'message' => 'Write requests are previewed during setup and are not sent.',
            ];
        }

        $response = $this->requestExecutor->executeRequest(
            new ApiOperation($values),
            $dataSource,
            $method,
            $url,
            $query,
            $body,
            retryConnection: false,
        );

        $preview = $this->preview($response['data'], $response['status'], $response['url']);
        $preview['method'] = $method;

        return $preview;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parameterRows(mixed $input): array
    {
        if (! is_array($input)) {
            return [];
        }

        if (! array_is_list($input)) {
            $input = array_map(fn (mixed $value, string|int $name): array => [
                'name' => (string) $name,
                'source' => 'tool_argument',
                'value' => is_scalar($value) ? $value : '',
            ], $input, array_keys($input));
        }

        return array_values(array_filter($input, 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $properties
     * @param  list<string>  $required
     * @param  array<string, mixed>  $mapping
     */
    private function addParameter(array $row, array &$properties, array &$required, array &$mapping, string $section): void
    {
        $name = (string) ($row['name'] ?? '');

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,254}$/', $name) !== 1) {
            return;
        }

        $source = (string) ($row['source'] ?? 'tool_argument');

        if ($source === 'fixed') {
            $mapping['fixed'][$section][$name] = $row['value'] ?? null;

            return;
        }

        $argument = (string) ($row['argument'] ?? $name);
        $type = (string) ($row['type'] ?? 'string');
        $properties[$argument] = ['type' => in_array($type, ['string', 'integer', 'number', 'boolean'], true) ? $type : 'string'];
        $mapping[$section][$argument] = $name;

        if (($row['required'] ?? false) === true || ($row['required'] ?? false) === 'true' || ($row['required'] ?? false) === 1 || ($row['required'] ?? false) === '1') {
            $required[] = $argument;
        }
    }

    /**
     * @param  array<string, mixed>  $mapping
     * @return array<string, mixed>
     */
    private function liveResponseMapping(array $mapping, mixed $fields): array
    {
        if (isset($mapping['output']) || isset($mapping['collection'])) {
            return $mapping;
        }

        $output = [];

        foreach ($this->parameterRows($fields) as $field) {
            $name = (string) ($field['name'] ?? '');
            $path = (string) ($field['path'] ?? '');

            if ($name !== '' && $path !== '') {
                $supportedTypes = ['string', 'integer', 'decimal', 'boolean', 'date', 'datetime'];
                $type = (string) ($field['type'] ?? 'string');
                $normalizedType = in_array($type, $supportedTypes, true) ? $type : 'string';

                $output[$name] = [
                    'path' => $path,
                    'required' => (bool) ($field['required'] ?? true),
                    'type' => $normalizedType,
                    'searchable' => (bool) ($field['searchable'] ?? in_array($normalizedType, ['string', 'date', 'datetime'], true)),
                    'filterable' => (bool) ($field['filterable'] ?? true),
                    'sortable' => (bool) ($field['sortable'] ?? in_array($normalizedType, ['string', 'integer', 'decimal', 'date', 'datetime'], true)),
                    'displayable' => (bool) ($field['displayable'] ?? true),
                ];
            }
        }

        return $output === [] ? $mapping : ['output' => $output];
    }

    /** @return array<string, scalar|null> */
    private function keyValueObject(mixed $value): array
    {
        if (is_array($value) && ! array_is_list($value)) {
            return array_filter($value, fn (mixed $item): bool => is_scalar($item) || $item === null);
        }

        if (! is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = (string) ($row['name'] ?? '');
            $item = $row['value'] ?? null;

            if ($name !== '' && (is_scalar($item) || $item === null)) {
                $result[$name] = $item;
            }
        }

        return $result;
    }

    /** @return list<string> */
    private function placeholders(string $path): array
    {
        preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $path, $matches);

        return array_values(array_unique(array_map('strval', $matches[1])));
    }

    private function mode(string $mode): string
    {
        return match ($mode) {
            'synced', 'sync', 'synced_data' => 'synced',
            'live_write', 'write' => 'live_write',
            default => 'live_read',
        };
    }

    private function path(string $path): string
    {
        return Str::startsWith($path, '/') ? $path : '/'.$path;
    }

    /** @param array<mixed, mixed> $config */
    private function url(array $config, string $path): string
    {
        return rtrim((string) ($config['base_url'] ?? ''), '/').'/'.ltrim($path, '/');
    }

    /**
     * @param  array<string, mixed>  $mapping
     * @return array<string|int, mixed>
     */
    private function fixedValues(array $mapping, string $section, bool $nested = false): array
    {
        $values = [];

        foreach ((array) Arr::get($mapping, 'fixed.'.$section, []) as $key => $value) {
            if ($nested) {
                Arr::set($values, (string) $key, $value);
            } else {
                $values[(string) $key] = $value;
            }
        }

        return $values;
    }

    private function safeUrl(string $url): string
    {
        $parts = parse_url($url);

        return is_array($parts) ? ((string) ($parts['scheme'] ?? 'https').'://'.((string) ($parts['host'] ?? '')).((string) ($parts['path'] ?? ''))) : '';
    }

    /** @return array<string, mixed> */
    private function graphqlSchemaType(string $type): array
    {
        $base = trim($type, '!');

        if (Str::startsWith($base, '[')) {
            return ['type' => 'array'];
        }

        return [
            'type' => match (Str::lower($base)) {
                'int' => 'integer',
                'float' => 'number',
                'boolean' => 'boolean',
                'string', 'id' => 'string',
                default => 'object',
            },
        ];
    }

    private function graphqlFixedValue(string $type, mixed $value): mixed
    {
        if ($value === null || ! is_string($value)) {
            return $value;
        }

        $base = trim($type, '!');

        if (Str::startsWith($base, '[')) {
            $decoded = json_decode($value, true);

            if (! is_array($decoded)) {
                throw new GraphqlRequestException('graphql_query_invalid', 'A fixed GraphQL list variable is invalid.');
            }

            return $decoded;
        }

        $base = Str::lower($base);

        if ($base === 'int') {
            return filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE)
                ?? throw new GraphqlRequestException('graphql_query_invalid', 'A fixed GraphQL integer variable is invalid.');
        }

        if ($base === 'float') {
            if (! is_numeric($value)) {
                throw new GraphqlRequestException('graphql_query_invalid', 'A fixed GraphQL number variable is invalid.');
            }

            return (float) $value;
        }

        if ($base === 'boolean') {
            return match (Str::lower($value)) {
                'true' => true,
                'false' => false,
                default => throw new GraphqlRequestException('graphql_query_invalid', 'A fixed GraphQL boolean variable is invalid.'),
            };
        }

        return $value;
    }

    /**
     * @param  array<mixed, mixed>  $value
     * @return array<string|int, mixed>
     */
    private function bounded(array $value): array
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($json) || strlen($json) <= 50_000) {
            return $value;
        }

        return ['_preview' => Str::limit($json, 50_000, '…'), '_truncated' => true];
    }

    private function redact(mixed $value): mixed
    {
        if (is_array($value)) {
            $result = [];

            foreach ($value as $key => $item) {
                if (preg_match('/(?:authorization|credential|password|secret|token|api[_-]?key)/i', (string) $key) === 1) {
                    $result[$key] = '[redacted]';
                } else {
                    $result[$key] = $this->redact($item);
                }
            }

            return $result;
        }

        return $value;
    }
}
