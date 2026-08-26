<?php

namespace App\Services\Api;

use App\Services\Api\Exceptions\GraphqlRequestException;
use GraphQL\Error\SyntaxError;
use GraphQL\Language\AST\ListTypeNode;
use GraphQL\Language\AST\NamedTypeNode;
use GraphQL\Language\AST\NonNullTypeNode;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\Parser;
use GraphQL\Language\Source;

final class GraphqlDocumentInspector
{
    private const MAX_DOCUMENT_BYTES = 100_000;

    /**
     * Parse a GraphQL document and select one executable operation safely.
     *
     * @return array{operation_type: string, operation_name: ?string, variables: list<array{name: string, type: string, required: bool}>}
     */
    public function inspect(string $document, ?string $operationName = null): array
    {
        if (trim($document) === '' || strlen($document) > self::MAX_DOCUMENT_BYTES) {
            throw $this->invalid('The GraphQL document is empty or too large.');
        }

        try {
            $parsed = Parser::parse(new Source($document));
        } catch (SyntaxError $exception) {
            throw $this->invalid('The GraphQL document is not valid.', $exception);
        }

        $operations = [];

        foreach ($parsed->definitions as $definition) {
            if ($definition instanceof OperationDefinitionNode) {
                $operations[] = $definition;
            }
        }

        if ($operations === []) {
            throw $this->invalid('The GraphQL document does not contain an executable operation.');
        }

        $selected = $this->selectOperation($operations, $operationName);

        if (! in_array($selected->operation, ['query', 'mutation'], true)) {
            throw $this->invalid('GraphQL subscriptions are not supported.');
        }

        return [
            'operation_type' => $selected->operation,
            'operation_name' => $selected->name?->value,
            'variables' => array_values(array_map(
                fn ($definition): array => [
                    'name' => $definition->variable->name->value,
                    'type' => $this->typeName($definition->type),
                    'required' => $definition->type instanceof NonNullTypeNode,
                ],
                iterator_to_array($selected->variableDefinitions),
            )),
        ];
    }

    /**
     * @param  list<OperationDefinitionNode>  $operations
     */
    private function selectOperation(array $operations, ?string $operationName): OperationDefinitionNode
    {
        if ($operationName !== null && $operationName !== '') {
            foreach ($operations as $operation) {
                if ($operation->name?->value === $operationName) {
                    return $operation;
                }
            }

            throw $this->invalid('The requested GraphQL operation was not found.');
        }

        if (count($operations) > 1) {
            throw $this->invalid('An operation name is required when the document contains multiple operations.');
        }

        return $operations[0];
    }

    private function typeName(object $type): string
    {
        return match (true) {
            $type instanceof NonNullTypeNode => $this->typeName($type->type).'!',
            $type instanceof ListTypeNode => '['.$this->typeName($type->type).']',
            $type instanceof NamedTypeNode => $type->name->value,
            default => throw $this->invalid('The GraphQL variable type is invalid.'),
        };
    }

    private function invalid(string $message, ?\Throwable $previous = null): GraphqlRequestException
    {
        return new GraphqlRequestException('graphql_query_invalid', $message, $previous);
    }
}
