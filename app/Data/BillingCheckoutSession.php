<?php

namespace App\Data;

readonly class BillingCheckoutSession
{
    public function __construct(public string $id, public string $url) {}
}
