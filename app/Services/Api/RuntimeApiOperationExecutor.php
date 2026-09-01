<?php

namespace App\Services\Api;

use App\Enums\ApiOperationMode;
use App\Services\Api\Exceptions\GraphqlRequestException;
use App\Services\Api\LiveRead\LiveReadQueryPlan;
use App\Services\Api\LiveRead\LiveReadQueryPlanner;
use App\Services\Api\LiveRead\LiveReadRecordMatcher;
use App\Services\Conversations\ConversationCycleLogger;
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
        private readonly ConversationCycleLogger $cycleLogger,
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
     * Execute a bounded, already validated live-read plan. Pagination remains
     * server-controlled so the model never receives cursors or next URLs.
     */
    public function executeLiveRead(
        RuntimeApiOperation $runtimeOperation,
        LiveReadQueryPlan $plan,
    ): RuntimeApiResult {
        try {
            $this->assertUsable($runtimeOperation, ApiOperationMode::Read);
            $this->validateArguments($runtimeOperation, $plan->remoteArguments);
            $mappingValue = $runtimeOperation->operation->getAttribute('response_mapping');
            $mapping = is_array($mappingValue) ? $mappingValue : [];
            $fields = $this->mappedFieldNames($runtimeOperation);
            $records = [];
            $seen = [];
            $pages = 0;
            $candidates = 0;
            $remoteMoreAvailable = false;
            $moreAvailable = false;
            $truncated = false;
            $lastStatus = 200;
            $rawResponseItemCount = 0;
            $collectionExtractedItemCount = 0;
            $mappedItemCount = 0;
            $deduplicatedCandidateCount = 0;
            $matcherInputCount = 0;
            $matcherOutputCount = 0;
            $candidateBudgetClippedCount = 0;
            $nextUrl = null;
            $page = $this->paginationStart($mapping);
            $cursor = null;
            $deadline = microtime(true) + max(1, (int) config('live-read.timeout_seconds', 15));

            while ($pages < $plan->pageBudget && $candidates < $plan->candidateBudget && microtime(true) < $deadline) {
                try {
                    $response = $this->livePageResponse($runtimeOperation, $plan, $mapping, $nextUrl, $page, $cursor);
                } catch (GraphqlRequestException|InvalidArgumentException|LogicException|ImportException|ConnectionException $exception) {
                    if ($pages === 0) {
                        throw $exception;
                    }

                    $truncated = true;
                    $moreAvailable = true;
                    break;
                }
                $lastStatus = $response['status'];
                $pages++;
                $rawResponseItemCount += $this->rawResponseItemCount($response['data']);
                $collection = $this->responseCollection($mapping, $response['data']);
                $collectionExtractedItemCount += count($collection);
                $remainingBudget = max(0, $plan->candidateBudget - $candidates);
                $collectionWasClipped = count($collection) > $remainingBudget;
                $candidateBudgetClippedCount += max(0, count($collection) - $remainingBudget);
                $candidates += min(count($collection), $remainingBudget);
                $mapped = $this->mapResponse($runtimeOperation, $response['data'], min(count($collection), $remainingBudget));
                $mapped = array_is_list($mapped) ? $mapped : [];
                $mappedItemCount += count($mapped);
                $pageMatcherInputCount = 0;
                $pageMatcherOutputCount = 0;

                if ($collectionWasClipped) {
                    $truncated = true;
                    $moreAvailable = true;
                }

                foreach ($mapped as $record) {
                    if (! is_array($record)) {
                        continue;
                    }
                    $key = $this->recordKey($record);
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $deduplicatedCandidateCount++;
                    $matcherInputCount++;
                    $pageMatcherInputCount++;
                    if ($this->matchesMappedRecord($record, $plan->localFilters, $fields)
                        && app(LiveReadRecordMatcher::class)->matchesConstraints($record, $plan->localConstraints, $fields)
                        && app(LiveReadRecordMatcher::class)->matchesSearchText($record, $plan->localSearchText, $fields)) {
                        $records[] = $record;
                        $matcherOutputCount++;
                        $pageMatcherOutputCount++;
                    }
                }

                $this->cycleLogger->event('live_read.response.stages', [
                    'bot_id' => $runtimeOperation->bot->id,
                    'team_id' => $runtimeOperation->bot->team_id,
                    'operation_id' => $runtimeOperation->operation->id,
                    'operation' => $runtimeOperation->operation->key,
                    'page' => $pages,
                    'raw_http_status' => $response['status'],
                    'raw_response_item_count' => $this->rawResponseItemCount($response['data']),
                    'response_mapping_type' => array_key_exists('collection', $mapping) ? 'collection' : 'scalar',
                    'collection_path' => $this->collectionPath($mapping),
                    'collection_extracted_item_count' => count($collection),
                    'mapped_item_count' => count($mapped),
                    'matcher_input_count' => $pageMatcherInputCount,
                    'matcher_output_count' => $pageMatcherOutputCount,
                    'candidate_budget_remaining' => $remainingBudget,
                    'candidate_budget_clipped_count' => max(0, count($collection) - $remainingBudget),
                ]);

                [$remoteMoreAvailable, $nextUrl, $page, $cursor] = $this->nextLivePosition($mapping, $response['data'], $page, $cursor);
                $moreAvailable = $moreAvailable || count($collection) > $plan->effectiveResultLimit || $remoteMoreAvailable;
                $canStop = count($records) >= $plan->requestedMinimum && ! $plan->requiresCompleteOrdering;
                if ($canStop || ! $remoteMoreAvailable) {
                    break;
                }

                if ($collectionWasClipped || $candidates >= $plan->candidateBudget || $pages >= $plan->pageBudget || microtime(true) >= $deadline) {
                    $truncated = $remoteMoreAvailable;
                    break;
                }
            }

            if ($candidates > $plan->candidateBudget) {
                $truncated = true;
                $moreAvailable = true;
            }

            if ($remoteMoreAvailable && ($pages >= $plan->pageBudget || $candidates >= $plan->candidateBudget || microtime(true) >= $deadline)) {
                $truncated = true;
            }

            if ($plan->localSorts !== []) {
                $records = app(LiveReadRecordMatcher::class)->sort($records, $plan->localSorts);
            }
            $records = array_slice($records, 0, $plan->effectiveResultLimit);

            if ($plan->localConstraints !== []) {
                $this->cycleLogger->event('search_catalog.local_constraints.matched', [
                    'constraints' => $plan->localConstraints,
                    'candidates_before' => $matcherInputCount,
                    'candidates_after' => $matcherOutputCount,
                ]);
            }

            return RuntimeApiResult::success([
                'records' => $records,
                'meta' => [
                    'complete' => ! $truncated && ! $remoteMoreAvailable,
                    'truncated' => $truncated,
                    'more_available' => $moreAvailable,
                    'pages_fetched' => $pages,
                    'candidates_examined' => min($candidates, $plan->candidateBudget),
                    'effective_result_limit' => $plan->effectiveResultLimit,
                    'count_requirement_satisfied' => count($records) >= $plan->requestedMinimum,
                    'confirmed_empty' => $records === [] && ! $truncated && ! $remoteMoreAvailable,
                    'raw_response_item_count' => $rawResponseItemCount,
                    'collection_extracted_item_count' => $collectionExtractedItemCount,
                    'mapped_item_count' => $mappedItemCount,
                    'deduplicated_candidate_count' => $deduplicatedCandidateCount,
                    'matcher_input_count' => $matcherInputCount,
                    'matcher_output_count' => $matcherOutputCount,
                    'candidate_budget_clipped_count' => $candidateBudgetClippedCount,
                ],
            ], $lastStatus);
        } catch (GraphqlRequestException $exception) {
            return RuntimeApiResult::failure($exception->errorType, 'The integration request could not be completed safely.');
        } catch (InvalidArgumentException|LogicException|ImportException|ConnectionException) {
            return RuntimeApiResult::failure('integration_error', 'The integration request could not be completed safely.');
        }
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
            logger()->notice('Runtime API operation request was rejected.', [
                'bot_id' => $runtimeOperation->bot->id,
                'team_id' => $runtimeOperation->bot->team_id,
                'operation' => $runtimeOperation->operation->key,
                'phase' => 'build',
                'exception' => $exception::class,
            ]);

            return RuntimeApiResult::failure($exception->errorType, 'The GraphQL operation is not configured correctly.');
        } catch (InvalidArgumentException $exception) {
            logger()->notice('Runtime API operation request was rejected.', [
                'bot_id' => $runtimeOperation->bot->id,
                'team_id' => $runtimeOperation->bot->team_id,
                'operation' => $runtimeOperation->operation->key,
                'phase' => 'validation',
                'exception' => $exception::class,
            ]);

            return RuntimeApiResult::failure(
                'invalid_request',
                'The integration request arguments are invalid.',
            );
        } catch (LogicException|ImportException $exception) {
            logger()->notice('Runtime API operation request was rejected.', [
                'bot_id' => $runtimeOperation->bot->id,
                'team_id' => $runtimeOperation->bot->team_id,
                'operation' => $runtimeOperation->operation->key,
                'phase' => 'build',
                'exception' => $exception::class,
            ]);

            return RuntimeApiResult::failure(
                'integration_error',
                'The integration operation is not configured correctly.',
            );
        }

        try {
            logger()->info('Runtime API operation search started.', [
                'bot_id' => $runtimeOperation->bot->id,
                'team_id' => $runtimeOperation->bot->team_id,
                'operation' => $runtimeOperation->operation->key,
                'source_id' => $runtimeOperation->dataSource->id,
                'source_type' => $runtimeOperation->dataSource->type,
                'method' => is_array($request) ? $request['method'] : 'GRAPHQL',
                'url' => is_array($request) ? $request['url'] : null,
                'query_keys' => is_array($request) ? array_keys($request['query']) : [],
                'body_keys' => is_array($request) ? array_keys($request['body']) : [],
            ]);

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

            logger()->info('Runtime API operation response received.', [
                'bot_id' => $runtimeOperation->bot->id,
                'team_id' => $runtimeOperation->bot->team_id,
                'operation' => $runtimeOperation->operation->key,
                'status' => $response['status'],
                'response_keys' => array_keys($response['data']),
                'response_is_list' => $this->isList($response['data']),
                'response_item_count' => $this->isList($response['data']) ? count($response['data']) : null,
            ]);

            $mappedResponse = $this->mapResponse($runtimeOperation, $response['data']);

            logger()->info('Runtime API operation response mapped.', [
                'bot_id' => $runtimeOperation->bot->id,
                'team_id' => $runtimeOperation->bot->team_id,
                'operation' => $runtimeOperation->operation->key,
                'status' => $response['status'],
                'mapped_keys' => array_keys($mappedResponse),
                'mapped_is_list' => array_is_list($mappedResponse),
                'mapped_item_count' => array_is_list($mappedResponse) ? count($mappedResponse) : null,
            ]);

            return RuntimeApiResult::success(
                $mappedResponse,
                $response['status'],
            );
        } catch (GraphqlRequestException $exception) {
            logger()->warning('Runtime API operation GraphQL request failed.', [
                'bot_id' => $runtimeOperation->bot->id,
                'team_id' => $runtimeOperation->bot->team_id,
                'operation' => $runtimeOperation->operation->key,
                'exception' => $exception::class,
            ]);

            return RuntimeApiResult::failure($exception->errorType, 'The GraphQL operation could not be completed safely.');
        } catch (InvalidArgumentException|LogicException $exception) {
            logger()->warning('Runtime API operation response mapping failed.', [
                'bot_id' => $runtimeOperation->bot->id,
                'team_id' => $runtimeOperation->bot->team_id,
                'operation' => $runtimeOperation->operation->key,
                'exception' => $exception::class,
            ]);

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
            $previousException = $exception->getPrevious();

            logger()->warning('Runtime API operation failed.', [
                'bot_id' => $runtimeOperation->bot->id,
                'team_id' => $runtimeOperation->bot->team_id,
                'operation' => $runtimeOperation->operation->key,
                'exception' => $exception::class,
                'reason' => $exception->getMessage(),
                'previous_exception' => $previousException instanceof Throwable ? $previousException::class : null,
                'previous_message' => $previousException?->getMessage(),
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
    private function request(
        RuntimeApiOperation $runtimeOperation,
        array $arguments,
        ?string $unmappedSearchParameter = null,
        ?string $unmappedSearchText = null,
    ): array {
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
        $body = $this->bodyValues($mapping, $arguments);
        $method = Str::upper((string) $operation->getAttribute('method'));

        if ($unmappedSearchParameter !== null && $unmappedSearchText !== null) {
            [$query, $body] = $this->addUnmappedSearchParameter(
                $query,
                $body,
                $mapping,
                $unmappedSearchParameter,
                $unmappedSearchText,
            );
        }

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
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function addUnmappedSearchParameter(
        array $query,
        array $body,
        array $mapping,
        string $parameter,
        string $text,
    ): array {
        foreach (['query', 'body'] as $section) {
            $sectionMapping = $mapping[$section] ?? [];

            if (! is_array($sectionMapping) || ! in_array($parameter, $sectionMapping, true)) {
                continue;
            }

            if ($section === 'query') {
                $query[$parameter] = $text;
            } else {
                Arr::set($body, $parameter, $text);
            }

            return [$query, $body];
        }

        $query[$parameter] = $text;

        return [$query, $body];
    }

    /**
     * @param  array<string, mixed>  $mapping
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function bodyValues(array $mapping, array $arguments): array
    {
        return array_replace_recursive(
            $this->fixedParameterMapping($mapping, 'body', nested: true),
            $this->parameterMapping($mapping, 'body', $arguments, true),
        );
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
    private function mapResponse(RuntimeApiOperation $runtimeOperation, array $response, ?int $collectionLimit = null): array
    {
        $mapping = $runtimeOperation->operation->getAttribute('response_mapping');

        if (is_array($mapping) && array_key_exists('collection', $mapping)) {
            if (array_key_exists('output', $mapping) || array_key_exists('fields', $mapping)) {
                throw new LogicException('The response mapping cannot mix collection and scalar output.');
            }

            return $this->mapCollection($mapping['collection'], $response, $collectionLimit);
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
    private function mapCollection(mixed $definition, array $response, ?int $collectionLimit = null): array
    {
        if (! is_array($definition)
            || ! is_string($definition['path'] ?? null)
            || $definition['path'] === ''
            || ! is_array($definition['fields'] ?? null)
            || $definition['fields'] === []) {
            throw new LogicException('The response collection mapping is invalid.');
        }

        $configuredLimit = $definition['limit'] ?? max(
            self::MAX_MAPPED_COLLECTION_ITEMS,
            (int) config('live-read.max_candidates', self::MAX_MAPPED_COLLECTION_ITEMS),
        );

        if (! is_int($configuredLimit) || $configuredLimit < 1) {
            throw new LogicException('The response collection limit is invalid.');
        }

        $limit = $collectionLimit === null
            ? min($configuredLimit, self::MAX_MAPPED_COLLECTION_ITEMS)
            : max(0, $collectionLimit);
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

    /** @return array<string, array<string, mixed>> */
    private function mappedFieldNames(RuntimeApiOperation $runtimeOperation): array
    {
        return app(LiveReadQueryPlanner::class)->fields($runtimeOperation->operation);
    }

    /** @param array<string, mixed> $mapping */
    private function paginationStart(array $mapping): int
    {
        $pagination = (array) ($mapping['pagination'] ?? []);

        return max(1, (int) ($pagination['start'] ?? 1));
    }

    /**
     * @param  array<string, mixed>  $mapping
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    private function responseCollection(array $mapping, array $response): array
    {
        $definition = is_array($mapping['collection'] ?? null) ? $mapping['collection'] : null;
        $path = is_string($definition['path'] ?? null) ? $definition['path'] : ($mapping['records_path'] ?? 'root');
        $value = $this->sourcePathResolver->get($response, (string) $path);

        if ($value === null) {
            return [];
        }
        if (! is_array($value)) {
            throw new LogicException('The live response collection is not an array.');
        }

        return array_values(array_filter($value, 'is_array'));
    }

    /** @param array<string, mixed> $response */
    private function rawResponseItemCount(array $response): int
    {
        if ($this->isList($response)) {
            return count($response);
        }

        foreach (['data', 'items', 'products', 'results'] as $key) {
            $value = $response[$key] ?? null;

            if (is_array($value) && array_is_list($value)) {
                return count($value);
            }
        }

        return 0;
    }

    /** @param array<int|string, mixed> $value */
    private function isList(array $value): bool
    {
        return array_is_list($value);
    }

    /** @param array<string, mixed> $mapping */
    private function collectionPath(array $mapping): string
    {
        $collection = $mapping['collection'] ?? null;

        return is_array($collection) && is_string($collection['path'] ?? null)
            ? $collection['path']
            : (string) ($mapping['records_path'] ?? 'root');
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

    /**
     * @param  array<string, mixed>  $mapping
     * @return array{data: array<string, mixed>, status: int, url: string}
     */
    private function livePageResponse(
        RuntimeApiOperation $runtimeOperation,
        LiveReadQueryPlan $plan,
        array $mapping,
        ?string $nextUrl,
        int $page,
        mixed $cursor,
    ): array {
        if ($runtimeOperation->dataSource->type === 'graphql_api') {
            $pagination = (array) ($mapping['pagination'] ?? []);
            $cursorVariable = $pagination['cursor_variable'] ?? $pagination['variable'] ?? null;
            $overrides = is_string($cursorVariable) && $cursor !== null ? [$cursorVariable => $cursor] : [];

            return $this->graphqlRequestExecutor->execute(
                $runtimeOperation->operation,
                $runtimeOperation->dataSource,
                $plan->remoteArguments,
                variableOverrides: $overrides,
            );
        }

        $request = $nextUrl !== null
            ? ['method' => Str::upper((string) $runtimeOperation->operation->method), 'url' => $nextUrl, 'query' => [], 'body' => []]
            : $this->request(
                $runtimeOperation,
                $plan->remoteArguments,
                $plan->remoteSearchParameter,
                $plan->remoteSearchText,
            );
        if ($nextUrl === null) {
            $request['query'] = [...$request['query'], ...$plan->remoteQuery];
            $request['body'] = array_replace_recursive($request['body'], $plan->remoteBody);
        }
        $pagination = (array) ($mapping['pagination'] ?? []);
        if (($pagination['type'] ?? null) === 'page' && $nextUrl === null) {
            $parameter = (string) ($pagination['parameter'] ?? 'page');
            $request['query'][$parameter] = $page;
        }
        foreach ($plan->remoteSorts as $sort) {
            $remote = $sort['remote'] ?? null;
            if (is_array($remote) && is_string($remote['parameter'] ?? null)) {
                $request['query'][$remote['parameter']] = $remote['value'] ?? $sort['field'].'_'.$sort['direction'];
            } elseif (is_string($remote) && $remote !== '') {
                $request['query'][$remote] = $sort['field'].'_'.$sort['direction'];
            }
        }

        $this->cycleLogger->event('live_api.request.prepared', [
            'bot_id' => $runtimeOperation->bot->id,
            'team_id' => $runtimeOperation->bot->team_id,
            'operation_id' => $runtimeOperation->operation->id,
            'operation' => $runtimeOperation->operation->key,
            'method' => $request['method'],
            'url' => $this->debugUrl($request['url']),
            'query' => $this->debugValue($request['query']),
            'body' => $this->debugValue($request['body']),
        ]);

        return $this->requestExecutor->executeRequest(
            $runtimeOperation->operation,
            $runtimeOperation->dataSource,
            $request['method'],
            $request['url'],
            $request['query'],
            $request['body'],
        );
    }

    /**
     * @param  array<string, mixed>  $mapping
     * @param  array<string, mixed>  $response
     * @return array{0: bool, 1: ?string, 2: int, 3: mixed}
     */
    private function nextLivePosition(array $mapping, array $response, int $page, mixed $cursor): array
    {
        $pagination = (array) ($mapping['pagination'] ?? []);
        $type = (string) ($pagination['type'] ?? 'none');
        if ($type === 'next_url') {
            $next = $this->sourcePathResolver->get($response, (string) ($pagination['next_path'] ?? ''));

            return [is_string($next) && $next !== '', is_string($next) && $next !== '' ? $next : null, $page, $cursor];
        }
        if ($type === 'page') {
            $current = $this->sourcePathResolver->get($response, (string) ($pagination['current_path'] ?? 'meta.current_page'));
            $last = $this->sourcePathResolver->get($response, (string) ($pagination['last_path'] ?? 'meta.last_page'));
            $hasNext = is_numeric($current) && is_numeric($last) ? (int) $current < (int) $last : count($this->responseCollection($mapping, $response)) > 0;

            return [$hasNext, null, $page + 1, $cursor];
        }
        if ($type === 'relay_cursor') {
            $hasNext = (bool) $this->sourcePathResolver->get($response, (string) ($pagination['has_next_path'] ?? 'pageInfo.hasNextPage'));
            $nextCursor = $this->sourcePathResolver->get($response, (string) ($pagination['cursor_path'] ?? 'pageInfo.endCursor'));

            return [$hasNext && $nextCursor !== null, null, $page, $nextCursor];
        }

        return [false, null, $page, $cursor];
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  list<array<string, mixed>>  $filters
     * @param  array<string, array<string, mixed>>  $fields
     */
    private function matchesMappedRecord(array $record, array $filters, array $fields): bool
    {
        return app(LiveReadRecordMatcher::class)->matches($record, $filters, $fields);
    }

    /** @param array<string, mixed> $record */
    private function recordKey(array $record): string
    {
        foreach (['id', 'key', 'code', 'reference'] as $field) {
            if (isset($record[$field]) && is_scalar($record[$field])) {
                return $field.':'.(string) $record[$field];
            }
        }

        return 'hash:'.sha1(json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: serialize($record));
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
            return [$definition['path'], (bool) ($definition['required'] ?? false)];
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
