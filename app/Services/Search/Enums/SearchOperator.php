<?php

namespace App\Services\Search\Enums;

enum SearchOperator: string
{
    case Equal = 'eq';
    case NotEqual = 'neq';
    case GreaterThan = 'gt';
    case GreaterThanOrEqual = 'gte';
    case LessThan = 'lt';
    case LessThanOrEqual = 'lte';
    case Contains = 'contains';
    case Between = 'between';
}
