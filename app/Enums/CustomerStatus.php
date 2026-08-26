<?php

namespace App\Enums;

enum CustomerStatus: string
{
    case New = 'new';
    case Active = 'active';
    case Qualified = 'qualified';
    case Customer = 'customer';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Active => 'Active',
            self::Qualified => 'Qualified',
            self::Customer => 'Customer',
            self::Inactive => 'Inactive',
        };
    }
}
