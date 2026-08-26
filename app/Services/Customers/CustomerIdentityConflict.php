<?php

namespace App\Services\Customers;

use RuntimeException;

final class CustomerIdentityConflict extends RuntimeException
{
    public function __construct(public readonly string $field)
    {
        parent::__construct("A customer with this {$field} already exists in the team.");
    }
}
