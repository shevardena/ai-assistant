<?php

namespace App\Enums;

enum ApiProtocol: string
{
    case Rest = 'rest';
    case Graphql = 'graphql';

    public static function fromDataSourceType(string $type): ?self
    {
        return match ($type) {
            'rest_api' => self::Rest,
            'graphql_api' => self::Graphql,
            default => null,
        };
    }
}
