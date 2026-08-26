<?php

namespace App\Exceptions;

use App\Enums\PlanLimit;
use RuntimeException;

class PlanLimitExceededException extends RuntimeException
{
    public function __construct(public readonly PlanLimit $limit)
    {
        parent::__construct("The team's {$limit->value} plan limit has been reached.");
    }
}
