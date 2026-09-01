<?php

use App\Models\ApiOperation;
use App\Services\Api\LiveRead\LiveReadQuery;
use App\Services\Api\LiveRead\LiveReadQueryPlanner;
use App\Services\Api\LiveRead\LiveReadRecordMatcher;
use App\Services\Api\LiveRead\YearRangeParser;
use App\Services\Imports\SourcePathResolver;

function liveOperation(array $mapping, array $requestMapping = []): ApiOperation
{
    $operation = Mockery::mock(ApiOperation::class);
    $operation->shouldReceive('getAttribute')->with('request_mapping')->andReturn($requestMapping);
    $operation->shouldReceive('getAttribute')->with('response_mapping')->andReturn($mapping);
    $operation->shouldReceive('__get')->with('request_mapping')->andReturn($requestMapping);
    $operation->shouldReceive('__get')->with('response_mapping')->andReturn($mapping);

    return $operation;
}

test('plans arbitrary mapped fields without product field assumptions', function () {
    $operation = liveOperation([
        'collection' => [
            'path' => 'data',
            'fields' => [
                'capacity' => ['path' => 'capacity', 'type' => 'decimal'],
                'city' => ['path' => 'city', 'type' => 'string'],
            ],
        ],
    ]);

    $plan = (new LiveReadQueryPlanner)->plan($operation, LiveReadQuery::fromArguments([
        'filters' => [['field' => 'capacity', 'operator' => 'lt', 'value' => '5.5']],
        'sorts' => [['field' => 'city', 'direction' => 'asc']],
        'result_count' => ['mode' => 'exact', 'value' => 3],
    ]));

    expect($plan->localFilters)->toHaveCount(1)
        ->and($plan->localFilters[0]['field'])->toBe('capacity')
        ->and($plan->effectiveResultLimit)->toBe(3);
});

test('classifies filters by configured remote capability and mapped local capability', function () {
    $operation = liveOperation([
        'collection' => [
            'path' => 'data',
            'fields' => [
                'category' => ['path' => 'category', 'type' => 'string'],
                'price' => ['path' => 'price', 'type' => 'decimal'],
            ],
        ],
    ], [
        'live_query' => [
            'filters' => ['category' => ['eq' => 'category_name']],
        ],
    ]);

    $plan = (new LiveReadQueryPlanner)->plan($operation, LiveReadQuery::fromArguments([
        'filters' => [
            ['field' => 'category', 'operator' => 'eq', 'value' => 'bumper'],
            ['field' => 'price', 'operator' => 'lte', 'value' => '200'],
            ['field' => 'horsepower', 'operator' => 'gt', 'value' => 300],
        ],
    ]));

    expect($plan->remoteFilters)->toMatchArray([
        [
            'field' => 'category',
            'operator' => 'eq',
            'value' => 'bumper',
            'parameters' => ['category_name' => 'bumper'],
            'strategy' => 'single_parameter',
        ],
    ])
        ->and($plan->remoteQuery)->toBe(['category_name' => 'bumper'])
        ->and($plan->localFilters)->toBe([
            ['field' => 'price', 'operator' => 'lte', 'value' => '200'],
        ])
        ->and($plan->unsupportedFilters[0])->toMatchArray([
            'field' => 'horsepower',
            'operator' => 'gt',
            'value' => 300,
            'reason' => 'field_not_mapped',
        ]);
});

test('resolves semantic price roles to source-specific live fields', function () {
    $operation = liveOperation([
        'collection' => [
            'path' => 'data',
            'fields' => [
                'price' => ['path' => 'price', 'type' => 'decimal', 'semantic_role' => 'current_price'],
                'old_price' => ['path' => 'old_price', 'type' => 'decimal', 'semantic_role' => 'regular_price'],
            ],
        ],
    ]);

    $planner = new LiveReadQueryPlanner;
    $plan = $planner->plan($operation, LiveReadQuery::fromArguments([
        'filters' => [['field' => 'current_price', 'operator' => 'between', 'value' => [100, 200]]],
        'sorts' => [['field' => 'current_price', 'direction' => 'asc']],
    ]));

    expect($planner->fields($operation)['current_price']['resolved_field'])->toBe('price')
        ->and($plan->localFilters)->toBe([
            ['field' => 'price', 'operator' => 'between', 'value' => [100, 200]],
        ])
        ->and($plan->localSorts)->toBe([
            ['field' => 'price', 'direction' => 'asc', 'type' => 'decimal'],
        ])
        ->and($plan->unsupportedFilters)->toBe([])
        ->and($plan->unsupportedSorts)->toBe([])
        ->and($planner->fields($operation)['discount_percent']['derived_from'])->toBe([
            'current_price' => 'price',
            'regular_price' => 'old_price',
        ]);
});

test('falls back to regular price for a generic current price request', function () {
    $operation = liveOperation([
        'collection' => [
            'path' => 'data',
            'fields' => [
                'original_amount' => [
                    'path' => 'original_amount',
                    'type' => 'decimal',
                    'semantic_role' => 'regular_price',
                ],
            ],
        ],
    ]);

    $planner = new LiveReadQueryPlanner;
    $fields = $planner->fields($operation);
    $plan = $planner->plan($operation, LiveReadQuery::fromArguments([
        'filters' => [['field' => 'current_price', 'operator' => 'lte', 'value' => 100]],
    ]));

    expect($fields['current_price']['resolved_field'])->toBe('original_amount')
        ->and($plan->localFilters)->toBe([
            ['field' => 'original_amount', 'operator' => 'lte', 'value' => 100],
        ])
        ->and($plan->unsupportedFilters)->toBe([]);
});

test('resolves the same semantic price role independently for different live sources', function () {
    $goParts = liveOperation([
        'collection' => [
            'path' => 'data',
            'fields' => [
                'price' => ['path' => 'price', 'type' => 'decimal', 'semantic_role' => 'current_price'],
            ],
        ],
    ]);
    $beko = liveOperation([
        'collection' => [
            'path' => 'data',
            'fields' => [
                'price' => ['path' => 'price', 'type' => 'decimal', 'semantic_role' => 'regular_price'],
                'promo_price' => ['path' => 'promo_price', 'type' => 'decimal', 'semantic_role' => 'current_price'],
            ],
        ],
    ]);

    $planner = new LiveReadQueryPlanner;
    $goPartsPlan = $planner->plan($goParts, LiveReadQuery::fromArguments([
        'filters' => [['field' => 'current_price', 'operator' => 'lte', 'value' => 150]],
    ]));
    $bekoPlan = $planner->plan($beko, LiveReadQuery::fromArguments([
        'filters' => [['field' => 'current_price', 'operator' => 'lte', 'value' => 1000]],
    ]));

    expect($goPartsPlan->localFilters[0]['field'])->toBe('price')
        ->and($bekoPlan->localFilters[0]['field'])->toBe('promo_price')
        ->and($goPartsPlan->unsupportedFilters)->toBe([])
        ->and($bekoPlan->unsupportedFilters)->toBe([]);
});

test('reports a semantic mapping reason when a requested price role is unavailable', function () {
    $operation = liveOperation([
        'collection' => [
            'path' => 'data',
            'fields' => [
                'capacity' => ['path' => 'capacity', 'type' => 'decimal'],
            ],
        ],
    ]);

    $plan = (new LiveReadQueryPlanner)->plan($operation, LiveReadQuery::fromArguments([
        'filters' => [['field' => 'current_price', 'operator' => 'lte', 'value' => 150]],
        'sorts' => [['field' => 'current_price', 'direction' => 'asc']],
    ]));

    expect($plan->unsupportedFilters[0]['reason'])->toBe('semantic_role_not_mapped')
        ->and($plan->unsupportedSorts[0]['reason'])->toBe('semantic_role_not_mapped');
});

test('does not treat an unmapped literal semantic field name as a price role', function () {
    $operation = liveOperation([
        'collection' => [
            'path' => 'data',
            'fields' => [
                'current_price' => ['path' => 'current_price', 'type' => 'decimal'],
            ],
        ],
    ]);

    $plan = (new LiveReadQueryPlanner)->plan($operation, LiveReadQuery::fromArguments([
        'filters' => [['field' => 'current_price', 'operator' => 'lte', 'value' => 150]],
    ]));

    expect($plan->localFilters)->toBe([])
        ->and($plan->unsupportedFilters[0]['reason'])->toBe('semantic_role_not_mapped');
});

test('uses only explicitly configured names for range filter pushdown', function () {
    $operation = liveOperation([
        'collection' => [
            'path' => 'data',
            'fields' => ['price' => ['path' => 'price', 'type' => 'decimal']],
        ],
    ], [
        'live_query' => [
            'filters' => [
                'price' => [
                    'lte' => [
                        'strategy' => 'range_parameters',
                        'from_parameter' => 'from_amount',
                        'to_parameter' => 'to_amount',
                    ],
                ],
            ],
        ],
    ]);

    $plan = (new LiveReadQueryPlanner)->plan($operation, LiveReadQuery::fromArguments([
        'filters' => [['field' => 'price', 'operator' => 'lte', 'value' => 200]],
    ]));

    expect($plan->remoteQuery)->toBe(['to_amount' => 200])
        ->and($plan->localFilters)->toBe([])
        ->and($plan->remoteFilters[0]['parameters'])->toBe(['to_amount' => 200]);
});

test('pushes semantic filters and sorts down using the resolved physical field', function () {
    $operation = liveOperation([
        'collection' => [
            'path' => 'data',
            'fields' => [
                'price' => ['path' => 'price', 'type' => 'decimal', 'semantic_role' => 'current_price'],
            ],
        ],
    ], [
        'live_query' => [
            'filters' => ['price' => ['lte' => 'max_price']],
            'sort' => ['price' => ['asc' => ['parameter' => 'order', 'value' => 'price_asc']]],
        ],
    ]);

    $plan = (new LiveReadQueryPlanner)->plan($operation, LiveReadQuery::fromArguments([
        'filters' => [['field' => 'current_price', 'operator' => 'lte', 'value' => 150]],
        'sorts' => [['field' => 'current_price', 'direction' => 'asc']],
    ]));

    expect($plan->remoteQuery)->toBe(['max_price' => 150])
        ->and($plan->remoteFilters[0]['field'])->toBe('price')
        ->and($plan->remoteSorts[0]['field'])->toBe('price')
        ->and($plan->localFilters)->toBe([])
        ->and($plan->localSorts)->toBe([]);
});

test('plans local and explicitly configured remote sort capabilities', function () {
    $operation = liveOperation([
        'collection' => [
            'path' => 'data',
            'fields' => [
                'price' => ['path' => 'price', 'type' => 'decimal'],
                'name' => ['path' => 'name', 'type' => 'string'],
            ],
        ],
    ], [
        'live_query' => [
            'sort' => [
                'name' => ['asc' => ['parameter' => 'order', 'value' => 'name_asc']],
            ],
        ],
    ]);

    $local = (new LiveReadQueryPlanner)->plan($operation, LiveReadQuery::fromArguments([
        'sorts' => [['field' => 'price', 'direction' => 'asc']],
    ]));
    $remote = (new LiveReadQueryPlanner)->plan($operation, LiveReadQuery::fromArguments([
        'sorts' => [['field' => 'name', 'direction' => 'asc']],
    ]));

    expect($local->sortMode)->toBe('local_bounded')
        ->and($local->globalSortGuaranteed)->toBeFalse()
        ->and($local->localSorts[0]['field'])->toBe('price')
        ->and($remote->sortMode)->toBe('remote_guaranteed')
        ->and($remote->globalSortGuaranteed)->toBeTrue()
        ->and($remote->remoteSorts[0]['remote'])->toBe([
            'parameter' => 'order',
            'value' => 'name_asc',
        ]);
});

test('classifies unsupported fields and incompatible operators without executing them', function () {
    $operation = liveOperation([
        'collection' => [
            'path' => 'data',
            'fields' => ['capacity' => ['path' => 'capacity', 'type' => 'decimal']],
        ],
    ]);

    $unsupportedField = (new LiveReadQueryPlanner)->plan($operation, LiveReadQuery::fromArguments([
        'filters' => [['field' => 'color', 'operator' => 'eq', 'value' => 'red']],
    ]));

    $incompatibleOperator = (new LiveReadQueryPlanner)->plan($operation, LiveReadQuery::fromArguments([
        'filters' => [['field' => 'capacity', 'operator' => 'contains', 'value' => '5']],
    ]));

    expect($unsupportedField->unsupportedFilters[0]['reason'])->toBe('field_not_mapped')
        ->and($incompatibleOperator->unsupportedFilters[0]['reason'])->toBe('operator_or_value_unsupported');
});

test('rejects numeric operators for fields explicitly mapped as strings', function () {
    $operation = liveOperation([
        'collection' => [
            'path' => 'data',
            'fields' => ['price' => ['path' => 'price', 'type' => 'string', 'filterable' => true]],
        ],
    ]);

    $plan = (new LiveReadQueryPlanner)->plan($operation, LiveReadQuery::fromArguments([
        'filters' => [['field' => 'price', 'operator' => 'lte', 'value' => 150]],
    ]));

    expect($plan->localFilters)->toBe([])
        ->and($plan->remoteFilters)->toBe([])
        ->and($plan->unsupportedFilters)->toMatchArray([
            ['field' => 'price', 'operator' => 'lte', 'value' => 150, 'reason' => 'operator_or_value_unsupported'],
        ]);
});

test('matches and sorts typed live records deterministically', function () {
    $matcher = new LiveReadRecordMatcher(new SourcePathResolver);
    $fields = ['capacity' => ['type' => 'decimal']];
    $records = [
        ['capacity' => '5.25'],
        ['capacity' => 3],
    ];

    expect($matcher->matches($records[0], [['field' => 'capacity', 'operator' => 'lt', 'value' => 5.5]], $fields))->toBeTrue()
        ->and($matcher->sort($records, [['field' => 'capacity', 'direction' => 'asc', 'type' => 'decimal']])[0]['capacity'])->toBe(3);
});

test('normalizes numeric and boolean values before local comparison', function () {
    $matcher = new LiveReadRecordMatcher(new SourcePathResolver);

    expect($matcher->matches(
        ['price' => '160.00'],
        [['field' => 'price', 'operator' => 'lte', 'value' => '200']],
        ['price' => ['type' => 'decimal']],
    ))->toBeTrue()
        ->and($matcher->matches(
            ['price' => '220.50'],
            [['field' => 'price', 'operator' => 'lte', 'value' => 200]],
            ['price' => ['type' => 'decimal']],
        ))->toBeFalse()
        ->and($matcher->matches(
            ['price' => 'not-a-number'],
            [['field' => 'price', 'operator' => 'neq', 'value' => 200]],
            ['price' => ['type' => 'decimal']],
        ))->toBeFalse()
        ->and($matcher->matches(
            ['available' => 'true'],
            [['field' => 'available', 'operator' => 'eq', 'value' => true]],
            ['available' => ['type' => 'boolean']],
        ))->toBeTrue();
});

test('keeps zero numeric and excludes invalid numeric response values', function () {
    $matcher = new LiveReadRecordMatcher(new SourcePathResolver);
    $filter = [['field' => 'price', 'operator' => 'lte', 'value' => 150]];
    $fields = ['price' => ['type' => 'decimal']];

    expect($matcher->matches(['price' => '0.00'], $filter, $fields))->toBeTrue()
        ->and($matcher->matches(['price' => '140.00'], $filter, $fields))->toBeTrue()
        ->and($matcher->matches(['price' => '160.00'], $filter, $fields))->toBeFalse()
        ->and($matcher->matches(['price' => 'N/A'], $filter, $fields))->toBeFalse();
});

test('derives discount percentages only from valid current and regular prices', function () {
    $matcher = new LiveReadRecordMatcher(new SourcePathResolver);
    $fields = [
        'discount_percent' => [
            'type' => 'decimal',
            'derived_from' => ['current_price' => 'promo_price', 'regular_price' => 'price'],
        ],
    ];

    expect($matcher->matches(
        ['promo_price' => '700.00', 'price' => '1000.00'],
        [['field' => 'discount_percent', 'operator' => 'gte', 'value' => 30]],
        $fields,
    ))->toBeTrue()
        ->and($matcher->matches(
            ['promo_price' => '950.00', 'price' => '1000.00'],
            [['field' => 'discount_percent', 'operator' => 'gte', 'value' => 30]],
            $fields,
        ))->toBeFalse()
        ->and($matcher->matches(
            ['promo_price' => 'N/A', 'price' => '1000.00'],
            [['field' => 'discount_percent', 'operator' => 'gte', 'value' => 30]],
            $fields,
        ))->toBeFalse();
});

test('clamps count intents to the platform result maximum', function () {
    $operation = liveOperation([
        'collection' => [
            'path' => 'data',
            'fields' => ['capacity' => ['path' => 'capacity', 'type' => 'decimal']],
        ],
    ]);

    $planner = new LiveReadQueryPlanner;
    $plan = $planner->plan($operation, LiveReadQuery::fromArguments([
        'result_count' => ['mode' => 'exact', 'value' => 1000],
    ]));

    expect($plan->effectiveResultLimit)->toBe(20)
        ->and($plan->requestedMinimum)->toBe(20);
});

test('translates every supported result count intent into bounded planner limits', function () {
    $operation = liveOperation([
        'collection' => [
            'path' => 'data',
            'fields' => ['name' => ['path' => 'name', 'type' => 'string']],
        ],
    ]);
    $planner = new LiveReadQueryPlanner;

    $minimum = $planner->plan($operation, LiveReadQuery::fromArguments([
        'result_count' => ['mode' => 'minimum', 'value' => 7],
    ]));
    $maximum = $planner->plan($operation, LiveReadQuery::fromArguments([
        'result_count' => ['mode' => 'maximum', 'value' => 7],
    ]));
    $range = $planner->plan($operation, LiveReadQuery::fromArguments([
        'result_count' => ['mode' => 'range', 'minimum' => 2, 'maximum' => 7],
    ]));
    $all = $planner->plan($operation, LiveReadQuery::fromArguments([
        'result_count' => ['mode' => 'all'],
    ]));

    expect($minimum->requestedMinimum)->toBe(7)
        ->and($minimum->effectiveResultLimit)->toBe(7)
        ->and($maximum->requestedMinimum)->toBe(5)
        ->and($maximum->effectiveResultLimit)->toBe(7)
        ->and($range->requestedMinimum)->toBe(2)
        ->and($range->effectiveResultLimit)->toBe(7)
        ->and($all->requestedMinimum)->toBe(5)
        ->and($all->effectiveResultLimit)->toBe(5);
});

test('uses local unicode text search when remote search is unavailable', function () {
    $matcher = new LiveReadRecordMatcher(new SourcePathResolver);

    expect($matcher->matchesSearchText(
        ['title' => 'ბამპერის ბადე'],
        'ბადე',
        ['title' => ['type' => 'string', 'searchable' => true]],
    ))->toBeTrue()
        ->and($matcher->matchesSearchText(
            ['title' => 'Toyota'],
            'Camry',
            ['title' => ['type' => 'string', 'searchable' => true]],
        ))->toBeFalse();
});

test('maps a configured remote search destination back to its operation argument', function () {
    $operation = liveOperation([
        'collection' => [
            'path' => 'data',
            'fields' => ['name' => ['path' => 'name', 'type' => 'string']],
        ],
    ], [
        'query' => ['search' => 'q'],
        'live_query' => ['search_text' => 'q'],
    ]);

    $plan = (new LiveReadQueryPlanner)->plan($operation, LiveReadQuery::fromArguments([
        'text' => 'camry',
    ]));

    expect($plan->remoteArguments)->toBe(['search' => 'camry'])
        ->and($plan->localSearchText)->toBeNull();
});

test('keeps a remote search parameter independent from operation arguments', function () {
    $operation = liveOperation([
        'collection' => [
            'path' => 'data',
            'fields' => ['title' => ['path' => 'name', 'type' => 'string']],
        ],
    ], [
        'query' => [],
        'live_query' => ['search_text' => 'name'],
    ]);

    $plan = (new LiveReadQueryPlanner)->plan($operation, LiveReadQuery::fromArguments([
        'text' => 'Camry',
    ]));

    expect($plan->remoteArguments)->toBe([])
        ->and($plan->remoteSearchParameter)->toBe('name')
        ->and($plan->remoteSearchText)->toBe('Camry')
        ->and($plan->localSearchText)->toBeNull();
});

test('exposes safe field metadata and rejects non-queryable fields', function () {
    $operation = liveOperation([
        'collection' => [
            'path' => 'data',
            'fields' => [
                'capacity' => ['path' => 'capacity', 'type' => 'decimal', 'filterable' => true, 'sortable' => true],
                'internal_code' => ['path' => 'internal_code', 'type' => 'string', 'queryable' => false],
            ],
        ],
    ]);
    $planner = new LiveReadQueryPlanner;

    expect($planner->fields($operation)['capacity'])->toMatchArray([
        'type' => 'decimal',
        'filterable' => true,
        'sortable' => true,
        'displayable' => true,
    ]);
    $unsupported = $planner->plan($operation, LiveReadQuery::fromArguments([
        'filters' => [['field' => 'internal_code', 'operator' => 'eq', 'value' => 'x']],
    ]));

    expect($unsupported->unsupportedFilters[0]['reason'])->toBe('field_not_filterable');
});

test('classifies descending numeric ranges as unsupported', function () {
    $operation = liveOperation([
        'collection' => ['path' => 'data', 'fields' => ['capacity' => ['path' => 'capacity', 'type' => 'decimal']]],
    ]);

    $plan = (new LiveReadQueryPlanner)->plan($operation, LiveReadQuery::fromArguments([
        'filters' => [['field' => 'capacity', 'operator' => 'between', 'value' => [10, 5]]],
    ]));

    expect($plan->unsupportedFilters[0]['reason'])->toBe('operator_or_value_unsupported');
});

test('maps semantic year constraints to the explicitly configured remote parameter', function () {
    $operation = liveOperation([
        'collection' => ['path' => 'data', 'fields' => ['title' => ['path' => 'name', 'type' => 'string', 'searchable' => true]]],
    ], [
        'query' => [],
        'live_query' => ['constraints' => ['year' => ['eq' => ['strategy' => 'single_parameter', 'remote_parameter' => 'y']]]],
    ]);

    $plan = (new LiveReadQueryPlanner)->plan($operation, LiveReadQuery::fromArguments([
        'text' => 'Prius',
        'constraints' => [['type' => 'year', 'operator' => 'eq', 'value' => 2009]],
    ]));

    expect($plan->remoteQuery)->toBe(['y' => 2009])
        ->and($plan->remoteConstraints[0]['parameters'])->toBe(['y' => 2009])
        ->and($plan->localConstraints)->toBe([]);
});

test('normalizes strict model constraint fields and year strings at the query boundary', function () {
    $query = LiveReadQuery::fromArguments([
        'constraints' => [[
            'field' => 'year',
            'operator' => 'eq',
            'value' => '2009',
        ]],
    ]);

    expect($query->constraints)->toBe([[
        'type' => 'year',
        'operator' => 'eq',
        'value' => 2009,
    ]]);
});

test('maps semantic year constraints to a configured year parameter', function () {
    $operation = liveOperation([
        'collection' => ['path' => 'data', 'fields' => ['title' => ['path' => 'name', 'type' => 'string', 'searchable' => true]]],
    ], [
        'query' => [],
        'live_query' => ['constraints' => ['year' => ['eq' => 'year']]],
    ]);

    $plan = (new LiveReadQueryPlanner)->plan($operation, LiveReadQuery::fromArguments([
        'constraints' => [['type' => 'year', 'operator' => 'eq', 'value' => 2009]],
    ]));

    expect($plan->remoteQuery)->toBe(['year' => 2009])
        ->and($plan->localConstraints)->toBe([]);
});

test('maps range constraints only according to configured from and to parameters', function () {
    $operation = liveOperation([
        'collection' => ['path' => 'data', 'fields' => ['title' => ['path' => 'name', 'type' => 'string', 'searchable' => true]]],
    ], [
        'query' => [],
        'live_query' => ['constraints' => ['year' => ['eq' => [
            'strategy' => 'range_parameters',
            'remote_from_parameter' => 'from',
            'remote_to_parameter' => 'to',
        ]]]],
    ]);

    $plan = (new LiveReadQueryPlanner)->plan($operation, LiveReadQuery::fromArguments([
        'constraints' => [['type' => 'year', 'operator' => 'eq', 'value' => 2009]],
    ]));

    expect($plan->remoteQuery)->toBe(['from' => 2009, 'to' => 2009])
        ->and($plan->localConstraints)->toBe([]);
});

test('keeps unmapped and remotely unsupported semantic constraints local', function () {
    $operation = liveOperation([
        'collection' => ['path' => 'data', 'fields' => ['title' => ['path' => 'name', 'type' => 'string', 'searchable' => true]]],
    ], [
        'query' => [],
        'live_query' => ['constraints' => ['year' => ['eq' => ['strategy' => 'single_parameter', 'remote_parameter' => 'year']]]],
    ]);

    $planner = new LiveReadQueryPlanner;
    $unmappedOperation = liveOperation([
        'collection' => ['path' => 'data', 'fields' => ['title' => ['path' => 'name', 'type' => 'string', 'searchable' => true]]],
    ], ['query' => []]);
    $unmapped = $planner->plan($unmappedOperation, LiveReadQuery::fromArguments([
        'constraints' => [['type' => 'year', 'operator' => 'eq', 'value' => 2009]],
    ]));
    $unsupportedOperator = $planner->plan($operation, LiveReadQuery::fromArguments([
        'constraints' => [['type' => 'year', 'operator' => 'gte', 'value' => 2009]],
    ]));
    $unknown = $planner->plan($operation, LiveReadQuery::fromArguments([
        'constraints' => [['type' => 'color', 'operator' => 'eq', 'value' => 'red']],
    ]));

    expect($unmapped->remoteQuery)->toBe([])
        ->and($unmapped->localConstraints)->toHaveCount(1)
        ->and($unsupportedOperator->remoteQuery)->toBe([])
        ->and($unsupportedOperator->localConstraints)->toHaveCount(1)
        ->and($unknown->remoteQuery)->toBe([])
        ->and($unknown->unsupportedConstraints)->toHaveCount(1);
});

test('keeps browse mode free of remote search parameters', function () {
    $operation = liveOperation([
        'collection' => ['path' => 'data', 'fields' => ['title' => ['path' => 'name', 'type' => 'string', 'searchable' => true]]],
    ], [
        'query' => [],
        'fixed' => ['query' => ['per_page' => 50]],
        'live_query' => ['search_text' => 'name'],
    ]);

    $plan = (new LiveReadQueryPlanner)->plan($operation, LiveReadQuery::fromArguments([
        'text' => null,
        'result_count' => ['mode' => 'all'],
    ]));

    expect($plan->remoteSearchParameter)->toBeNull()
        ->and($plan->remoteSearchText)->toBeNull()
        ->and($plan->remoteQuery)->toBe([])
        ->and($plan->remoteArguments)->toBe([]);
});

test('matches conservative textual and structured automotive year ranges', function () {
    $parser = new YearRangeParser;
    $matcher = new LiveReadRecordMatcher(new SourcePathResolver, $parser);
    $fields = [
        'title' => ['type' => 'string', 'searchable' => true, 'displayable' => true],
        'year_from' => ['type' => 'integer'],
        'year_to' => ['type' => 'integer'],
    ];

    expect($parser->parse('2009-2011 PRIUS'))->toBe(['from' => 2009, 'to' => 2011])
        ->and($parser->parse('09-11 PRIUS'))->toBe(['from' => 2009, 'to' => 2011])
        ->and($parser->parse('15mm bolt'))->toBeNull()
        ->and($parser->parse('12V battery'))->toBeNull()
        ->and($parser->parse('09A connector'))->toBeNull()
        ->and($matcher->matchesConstraints(['title' => '09-11 PRIUS - grille'], [['type' => 'year', 'operator' => 'eq', 'value' => 2009]], $fields))->toBeTrue()
        ->and($matcher->matchesConstraints(['title' => '12-15 PRIUS - bumper'], [['type' => 'year', 'operator' => 'eq', 'value' => 2009]], $fields))->toBeFalse()
        ->and($matcher->matchesConstraints(['year_from' => 2009, 'year_to' => 2011], [['type' => 'year', 'operator' => 'eq', 'value' => 2010]], $fields))->toBeTrue();
});
