<?php

namespace App\Enums;

enum WorkflowConditionOperator: string
{
    case Equals = 'equals';
    case NotEquals = 'not_equals';
}
