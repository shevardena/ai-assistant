<?php

namespace App\Services\Customers;

use App\Models\Customer;

final readonly class CustomerResolution
{
    public function __construct(
        public ?Customer $customer,
        public bool $conflict = false,
        public ?string $conflictField = null,
    ) {}

    public function isResolved(): bool
    {
        return $this->customer instanceof Customer;
    }
}
