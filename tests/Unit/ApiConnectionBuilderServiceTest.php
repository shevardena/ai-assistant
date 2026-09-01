<?php

use App\Services\Api\ApiConnectionBuilderService;

test('normalizes live search text separately from structured filter mappings', function () {
    $values = app(ApiConnectionBuilderService::class)->operationValues([
        'usage' => 'live_read',
        'method' => 'GET',
        'path' => '/products',
        'query_parameters' => [],
        'body_parameters' => [],
        'live_query' => [
            'search_text' => 'name',
            'filters' => [['field' => 'category', 'operator' => 'eq', 'remote' => 'category_name']],
        ],
        'response_fields' => [['name' => 'id', 'path' => 'id', 'required' => true]],
    ]);

    expect($values['request_mapping']['live_query'])->toBe([
        'search_text' => 'name',
        'filters' => ['category' => ['eq' => 'category_name']],
    ])
        ->and($values['request_mapping']['query'])->toBe([]);
});

test('accepts the legacy camel-case live query payload without changing its meaning', function () {
    $values = app(ApiConnectionBuilderService::class)->operationValues([
        'usage' => 'live_read',
        'method' => 'GET',
        'path' => '/products',
        'liveQuery' => ['searchText' => 'q', 'filters' => []],
        'response_fields' => [['name' => 'id', 'path' => 'id', 'required' => true]],
    ]);

    expect($values['request_mapping']['live_query']['search_text'])->toBe('q');
});

test('persists semantic constraint mappings without renaming client parameters', function () {
    $values = app(ApiConnectionBuilderService::class)->operationValues([
        'usage' => 'live_read',
        'method' => 'GET',
        'path' => '/products',
        'query_parameters' => [],
        'body_parameters' => [],
        'live_query' => [
            'search_text' => 'name',
            'filters' => [],
            'constraints' => [
                [
                    'type' => 'year',
                    'operator' => 'eq',
                    'strategy' => 'range_parameters',
                    'remote_from_parameter' => 'from',
                    'remote_to_parameter' => 'to',
                ],
            ],
        ],
        'response_fields' => [['name' => 'id', 'path' => 'id', 'required' => true]],
    ]);

    expect($values['request_mapping']['live_query']['constraints'])->toBe([
        'year' => [
            'eq' => [
                'strategy' => 'range_parameters',
                'remote_from_parameter' => 'from',
                'remote_to_parameter' => 'to',
            ],
        ],
    ]);
});

test('preserves legacy numeric response field metadata when rebuilding mappings', function () {
    $values = app(ApiConnectionBuilderService::class)->operationValues([
        'usage' => 'live_read',
        'method' => 'GET',
        'path' => '/products',
        'response_fields' => [
            ['name' => 'price', 'path' => 'price', 'data_type' => 'number'],
        ],
    ]);

    expect($values['response_mapping']['output']['price']['type'])->toBe('decimal');
});

test('persists semantic price roles with live response field metadata', function () {
    $values = app(ApiConnectionBuilderService::class)->operationValues([
        'usage' => 'live_read',
        'method' => 'GET',
        'path' => '/products',
        'response_fields' => [
            ['name' => 'promo_price', 'path' => 'promo_price', 'type' => 'decimal', 'semantic_role' => 'current_price'],
        ],
    ]);

    expect($values['response_mapping']['output']['promo_price'])->toMatchArray([
        'type' => 'decimal',
        'semantic_role' => 'current_price',
    ]);
});
