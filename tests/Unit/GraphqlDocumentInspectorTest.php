<?php

use App\Services\Api\Exceptions\GraphqlRequestException;
use App\Services\Api\GraphqlDocumentInspector;

test('the GraphQL inspector extracts operation variables and rejects subscriptions', function () {
    $definition = (new GraphqlDocumentInspector)->inspect('query Products($first: Int!, $after: String) { products(first: $first, after: $after) { id } }');

    expect($definition['operation_type'])->toBe('query')
        ->and($definition['operation_name'])->toBe('Products')
        ->and($definition['variables'])->toMatchArray([
            ['name' => 'first', 'type' => 'Int!', 'required' => true],
            ['name' => 'after', 'type' => 'String', 'required' => false],
        ]);

    expect(fn () => (new GraphqlDocumentInspector)->inspect('subscription Updates { updates { id } }'))
        ->toThrow(GraphqlRequestException::class);
});

test('multiple GraphQL operations require an explicit operation name', function () {
    $inspector = new GraphqlDocumentInspector;
    $document = 'query A { a } query B { b }';

    expect(fn () => $inspector->inspect($document))->toThrow(GraphqlRequestException::class);
    expect($inspector->inspect($document, 'B')['operation_name'])->toBe('B');
});
