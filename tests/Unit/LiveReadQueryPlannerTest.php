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

test('rejects unsupported fields and incompatible operators', function () {
    $operation = liveOperation([
        'collection' => [
            'path' => 'data',
            'fields' => ['capacity' => ['path' => 'capacity', 'type' => 'decimal']],
        ],
    ]);

    expect(fn () => (new LiveReadQueryPlanner)->plan($operation, LiveReadQuery::fromArguments([
        'filters' => [['field' => 'color', 'operator' => 'eq', 'value' => 'red']],
    ])))->toThrow(InvalidArgumentException::class);

    expect(fn () => (new LiveReadQueryPlanner)->plan($operation, LiveReadQuery::fromArguments([
        'filters' => [['field' => 'capacity', 'operator' => 'contains', 'value' => '5']],
    ])))->toThrow(InvalidArgumentException::class);
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
    expect(fn () => $planner->plan($operation, LiveReadQuery::fromArguments([
        'filters' => [['field' => 'internal_code', 'operator' => 'eq', 'value' => 'x']],
    ])))->toThrow(InvalidArgumentException::class);
});

test('rejects descending numeric ranges', function () {
    $operation = liveOperation([
        'collection' => ['path' => 'data', 'fields' => ['capacity' => ['path' => 'capacity', 'type' => 'decimal']]],
    ]);

    expect(fn () => (new LiveReadQueryPlanner)->plan($operation, LiveReadQuery::fromArguments([
        'filters' => [['field' => 'capacity', 'operator' => 'between', 'value' => [10, 5]]],
    ])))->toThrow(InvalidArgumentException::class);
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
