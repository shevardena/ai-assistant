<?php

namespace App\Services\Imports;

use App\Models\ApiOperation;
use App\Models\DataSource;
use App\Models\DataSourceCredential;
use App\Services\Imports\Exceptions\ImportException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

class RestApiRequestExecutor
{
    /**
     * Execute one safe JSON GET request.
     *
     * @param  array<string, mixed>  $query
     * @return array{data: array<string, mixed>, status: int, url: string}
     */
    public function execute(ApiOperation $operation, DataSource $dataSource, ?string $url = null, array $query = []): array
    {
        return $this->executeRequest($operation, $dataSource, 'GET', $url, $query);
    }

    /**
     * Execute one configured HTTP request while retaining the importer's URL,
     * credential, timeout, redirect, and response-size protections.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>|null  $credentialOverrides
     * @return array{data: array<string, mixed>, status: int, url: string}
     */
    public function executeRequest(
        ApiOperation $operation,
        DataSource $dataSource,
        string $method,
        ?string $url = null,
        array $query = [],
        array $body = [],
        bool $retryConnection = true,
        ?string $idempotencyKey = null,
        ?array $credentialOverrides = null,
    ): array {
        if (! in_array($dataSource->type, ['rest_api', 'graphql_api'], true)) {
            throw new ImportException('Only HTTP API data sources can execute requests.');
        }

        $method = Str::upper($method);

        if (! in_array($method, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true)) {
            throw new ImportException('The API request method is not supported.');
        }

        if ($body !== [] && in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            throw new ImportException('The configured request body is not supported for this method.');
        }

        $requestUrl = $url ?? $this->operationUrl($dataSource, $operation);
        $this->assertSafeUrl($requestUrl);

        return $this->executeJsonPayload(
            $operation,
            $dataSource,
            $requestUrl,
            payload: $body,
            method: $method,
            retryConnection: $retryConnection,
            idempotencyKey: $idempotencyKey,
            query: $query,
            credentialOverrides: $credentialOverrides,
        );
    }

    /**
     * Execute a safe JSON POST/HTTP payload for protocol-specific clients.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $credentialOverrides
     * @return array{data: array<string, mixed>, status: int, url: string}
     */
    public function executeJsonPayload(
        ApiOperation $operation,
        DataSource $dataSource,
        string $url,
        array $payload,
        string $method = 'POST',
        bool $retryConnection = true,
        ?string $idempotencyKey = null,
        array $query = [],
        ?array $credentialOverrides = null,
    ): array {
        $this->assertSafeUrl($url);

        $request = Http::withHeaders($this->headers($operation, $dataSource, $idempotencyKey, $credentialOverrides))
            ->acceptJson()
            ->asJson()
            ->withUserAgent((string) config('rest-sources.user_agent', 'AI-Search-Assistant/1.0'))
            ->connectTimeout((float) config('rest-sources.connect_timeout', 5))
            ->timeout($this->timeoutSeconds($operation))
            ->withOptions(['allow_redirects' => false]);

        if ($retryConnection) {
            $request = $request->retry(2, 100, fn (Throwable $exception): bool => $exception instanceof ConnectionException);
        }

        $query = [
            ...$this->defaultQueryParameters($dataSource),
            ...$this->authenticationQuery($dataSource, $credentialOverrides),
            ...$query,
        ];

        $options = ['query' => $query];

        if ($payload !== []) {
            $options['json'] = $payload;
        }

        try {
            logger()->info('REST API request started.', [
                'operation_id' => $operation->id,
                'operation' => $operation->key,
                'source_id' => $dataSource->id,
                'method' => Str::upper($method),
                'url' => $this->debugUrl($url),
                'query' => $this->debugValue($query),
                'body' => $this->debugValue($payload),
            ]);

            $response = $request->send(Str::upper($method), $url, $options);
        } catch (ConnectionException $exception) {
            logger()->warning('REST API request could not connect.', [
                'operation_id' => $operation->id,
                'operation' => $operation->key,
                'source_id' => $dataSource->id,
                'method' => Str::upper($method),
                'url' => $this->debugUrl($url),
                'exception' => $exception::class,
                'transport_error' => Str::limit($exception->getMessage(), 500),
            ]);

            throw new ImportException(
                'The API server could not be reached. Check the production server DNS, firewall, TLS certificate, and outbound HTTPS access.',
                previous: $exception,
            );
        } catch (RequestException $exception) {
            $status = $exception->response->status();

            logger()->warning('REST API request returned an unsuccessful status.', [
                'operation_id' => $operation->id,
                'operation' => $operation->key,
                'source_id' => $dataSource->id,
                'method' => Str::upper($method),
                'url' => $this->debugUrl($url),
                'status' => $status,
                'exception' => $exception::class,
            ]);

            throw new ImportException(
                "The API request returned HTTP {$status}.",
                previous: $exception,
            );
        }

        logger()->info('REST API response received.', [
            'operation_id' => $operation->id,
            'operation' => $operation->key,
            'source_id' => $dataSource->id,
            'status' => $response->status(),
            'content_type' => $response->header('content-type'),
            'content_length' => $response->header('content-length'),
        ]);

        $maxLiveResponseBytes = data_get($operation->response_mapping, 'sync_mode') === 'full_snapshot'
            ? 0
            : max(0, (int) config('live-read.max_response_bytes', 5 * 1024 * 1024));
        $contentLength = $response->header('content-length');
        if ($maxLiveResponseBytes > 0 && is_numeric($contentLength) && (int) $contentLength > $maxLiveResponseBytes) {
            throw new ImportException('The live API response exceeds the configured safety limit.');
        }

        try {
            $result = $this->parseResponse($response, $url);
            if ($maxLiveResponseBytes > 0 && strlen($response->body()) > $maxLiveResponseBytes) {
                throw new ImportException('The live API response exceeds the configured safety limit.');
            }

            return $result;
        } catch (ImportException $exception) {
            logger()->warning('REST API response was rejected.', [
                'operation_id' => $operation->id,
                'operation' => $operation->key,
                'source_id' => $dataSource->id,
                'status' => $response->status(),
                'reason' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            throw $exception;
        }
    }

    /**
     * Validate an external URL before every request, including next-page URLs.
     */
    public function assertSafeUrl(string $url): void
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new ImportException('The API URL is malformed.');
        }

        $scheme = Str::lower((string) $parts['scheme']);
        $host = Str::lower(rtrim((string) $parts['host'], '.'));

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new ImportException('The API URL must use HTTP or HTTPS.');
        }

        if ($host === '' || isset($parts['user'], $parts['pass'])) {
            throw new ImportException('The API URL is not allowed.');
        }

        if ($host === 'localhost' || Str::endsWith($host, '.localhost') || $host === 'metadata.google.internal') {
            throw new ImportException('The API URL targets a private or local host.');
        }

        if ($this->isForbiddenIp($host)) {
            throw new ImportException('The API URL targets a private or local address.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return;
        }

        if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new ImportException('The API URL host is invalid.');
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if (! is_array($records)) {
            return;
        }

        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address) && $this->isForbiddenIp($address)) {
                throw new ImportException('The API URL resolves to a private or local address.');
            }
        }
    }

    /**
     * @param  array<string, mixed>|null  $credentialOverrides
     * @return array<string, string>
     */
    private function headers(ApiOperation $operation, DataSource $dataSource, ?string $idempotencyKey = null, ?array $credentialOverrides = null): array
    {
        $configuredHeaders = [
            ...$this->defaultHeaders($dataSource),
            ...(array) $operation->headers,
        ];
        $headers = [];

        foreach ($configuredHeaders as $name => $value) {
            $headerName = (string) $name;

            if ($this->isForbiddenHeader($headerName) || Str::lower($headerName) === 'authorization') {
                throw new ImportException("The API operation header [{$name}] is not allowed.");
            }

            $this->addHeader($headers, $headerName, (string) $value);
        }

        $idempotencyHeader = Arr::get((array) $operation->request_mapping, 'idempotency_header');

        if ($idempotencyKey !== null && is_string($idempotencyHeader)) {
            if ($idempotencyHeader === ''
                || preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $idempotencyHeader) !== 1
                || $this->isForbiddenHeader($idempotencyHeader)
                || Str::lower($idempotencyHeader) === 'authorization') {
                throw new ImportException('The API idempotency header configuration is invalid.');
            }

            $this->addHeader($headers, $idempotencyHeader, $idempotencyKey);
        }

        $credentials = $credentialOverrides ?? $dataSource->credentials()->get()->keyBy('key')->mapWithKeys(
            fn (DataSourceCredential $credential): array => [(string) $credential->key => (string) $credential->encrypted_value],
        )->all();
        $bearerToken = $this->credential($credentials, 'bearer_token');
        $apiKey = $this->credential($credentials, 'api_key');
        $username = $this->credential($credentials, 'basic_username');
        $password = $this->credential($credentials, 'basic_password');

        $authType = Arr::get((array) $dataSource->config, 'auth_type');

        if ($bearerToken !== null && ($authType === null || $authType === 'bearer')) {
            $this->addHeader($headers, 'Authorization', 'Bearer '.$bearerToken);
        }

        if ($apiKey !== null && ($authType === null || $authType === 'api_key') && Arr::get((array) $dataSource->config, 'api_key_placement', 'header') === 'header') {
            $apiKeyHeader = Arr::get((array) $dataSource->config, 'api_key_name', Arr::get((array) $dataSource->config, 'api_key_header', 'X-API-Key'));

            if (! is_string($apiKeyHeader) || $this->isForbiddenHeader($apiKeyHeader) || Str::lower($apiKeyHeader) === 'authorization') {
                throw new ImportException('The API key header configuration is invalid.');
            }

            $this->addHeader($headers, (string) $apiKeyHeader, $apiKey);
        }

        if (($username === null) !== ($password === null)) {
            throw new ImportException('Both basic authentication credentials are required.');
        }

        if ($username !== null && $password !== null && ($authType === null || $authType === 'basic')) {
            $this->addHeader($headers, 'Authorization', 'Basic '.base64_encode($username.':'.$password));
        }

        $customHeaderValue = $this->credential($credentials, 'custom_header_value');

        if ($customHeaderValue !== null && ($authType === null || $authType === 'custom_header')) {
            $customHeaderName = (string) Arr::get((array) $dataSource->config, 'custom_header_name', 'X-API-Key');

            if ($customHeaderName === '' || preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $customHeaderName) !== 1 || $this->isForbiddenHeader($customHeaderName) || Str::lower($customHeaderName) === 'authorization') {
                throw new ImportException('The custom authentication header configuration is invalid.');
            }

            $this->addHeader($headers, $customHeaderName, $customHeaderValue);
        }

        return $headers;
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function addHeader(array &$headers, string $name, string $value): void
    {
        $headers[$name] = $value;
    }

    /** @param  array<string, mixed>  $credentials */
    private function credential(array $credentials, string $key): ?string
    {
        $credential = $credentials[$key] ?? null;

        if (! is_string($credential) || $credential === '') {
            return null;
        }

        return $credential;
    }

    /** @return array<string, scalar|null> */
    private function defaultQueryParameters(DataSource $dataSource): array
    {
        $parameters = Arr::get((array) $dataSource->config, 'default_query_parameters', []);

        return is_array($parameters)
            ? array_filter($parameters, fn (mixed $value): bool => is_scalar($value) || $value === null)
            : [];
    }

    /** @return array<string, string> */
    private function defaultHeaders(DataSource $dataSource): array
    {
        $headers = Arr::get((array) $dataSource->config, 'default_headers', []);

        if (! is_array($headers)) {
            return [];
        }

        $result = [];

        foreach ($headers as $name => $value) {
            if (is_string($name) && (is_scalar($value) || $value === null)) {
                $result[$name] = (string) $value;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>|null  $credentialOverrides
     * @return array<string, scalar|null>
     */
    private function authenticationQuery(DataSource $dataSource, ?array $credentialOverrides): array
    {
        $config = (array) $dataSource->config;

        if (Arr::get($config, 'auth_type') !== 'api_key' || Arr::get($config, 'api_key_placement', 'header') !== 'query') {
            return [];
        }

        $credentials = $credentialOverrides ?? $dataSource->credentials()->get()->keyBy('key')->mapWithKeys(
            fn (DataSourceCredential $credential): array => [(string) $credential->key => (string) $credential->encrypted_value],
        )->all();
        $apiKey = $this->credential($credentials, 'api_key');
        $name = Arr::get($config, 'api_key_name', Arr::get($config, 'api_key_query_parameter'));

        if ($apiKey === null || ! is_string($name) || preg_match('/^[A-Za-z_][A-Za-z0-9_.-]{0,99}$/', $name) !== 1) {
            throw new ImportException('The API key query parameter configuration is invalid.');
        }

        return [$name => $apiKey];
    }

    public function operationUrl(DataSource $dataSource, ApiOperation $operation, ?string $path = null): string
    {
        $baseUrl = Arr::get((array) $dataSource->config, 'base_url');

        if (! is_string($baseUrl) || $baseUrl === '') {
            throw new ImportException('Configure a base URL for this REST API data source.');
        }

        $path ??= $operation->path;

        if (! Str::startsWith($path, '/')
            || Str::startsWith($path, '//')
            || parse_url($path, PHP_URL_HOST) !== null) {
            throw new ImportException('API operation paths must be relative to the data source base URL.');
        }

        return rtrim($baseUrl, '/').'/'.ltrim($path, '/');
    }

    public function timeoutSeconds(ApiOperation $operation, ?float $maximum = null): float
    {
        $maximum ??= (float) config('rest-sources.timeout', 30);

        return self::boundTimeoutSeconds((int) $operation->timeout_ms, $maximum);
    }

    public static function boundTimeoutSeconds(int $timeoutMilliseconds, float $maximum): float
    {
        return max(1, min($timeoutMilliseconds / 1000, $maximum));
    }

    /**
     * @return array{data: array<string, mixed>, status: int, url: string}
     */
    private function parseResponse(Response $response, string $url): array
    {
        if ($response->status() >= 300 && $response->status() < 400) {
            throw new ImportException('The API response redirected to another URL, which is not allowed.');
        }

        if (! $response->successful()) {
            throw new ImportException("The API request returned HTTP {$response->status()}.");
        }

        $contentLength = $response->header('content-length');
        $maxResponseBytes = (int) config('rest-sources.max_response_bytes', 10485760);

        if ((int) $contentLength > $maxResponseBytes) {
            throw new ImportException('The API response exceeded the configured size limit.');
        }

        $body = $response->body();

        if (strlen($body) > $maxResponseBytes) {
            throw new ImportException('The API response exceeded the configured size limit.');
        }

        if ($response->status() === 204 || trim($body) === '') {
            throw new ImportException('The API response did not contain JSON.');
        }

        $contentType = Str::lower((string) $response->header('content-type'));

        if ($contentType !== '' && ! Str::contains($contentType, 'json')) {
            throw new ImportException('The API response was not JSON.');
        }

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ImportException('The API response was not valid JSON.', previous: $exception);
        }

        if (! is_array($data)) {
            throw new ImportException('The API response must contain a JSON object or array.');
        }

        return [
            'data' => $data,
            'status' => $response->status(),
            'url' => $url,
        ];
    }

    private function isForbiddenHeader(string $name): bool
    {
        return in_array(Str::lower($name), [
            'host',
            'content-length',
            'connection',
            'transfer-encoding',
        ], true);
    }

    private function debugUrl(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return '[invalid-url]';
        }

        return $parts['scheme'].'://'.$parts['host'].($parts['port'] ?? null ? ':'.$parts['port'] : '').($parts['path'] ?? '');
    }

    private function debugValue(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && preg_match('/(?:authorization|credential|password|secret|token|api[_-]?key)/i', $key) === 1) {
            return '[REDACTED]';
        }

        if (is_array($value)) {
            $result = [];

            foreach ($value as $childKey => $childValue) {
                $result[$childKey] = $this->debugValue($childValue, is_string($childKey) ? $childKey : null);
            }

            return $result;
        }

        if (is_string($value)) {
            return Str::limit($value, 500);
        }

        return is_scalar($value) || $value === null ? $value : '[REDACTED]';
    }

    private function isForbiddenIp(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
