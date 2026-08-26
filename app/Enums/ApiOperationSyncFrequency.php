<?php

namespace App\Enums;

enum ApiOperationSyncFrequency: string
{
    case Manual = 'manual';
    case Every15Minutes = 'every_15_minutes';
    case Hourly = 'hourly';
    case Every6Hours = 'every_6_hours';
    case Every12Hours = 'every_12_hours';
    case Daily = 'daily';

    public function minutes(): ?int
    {
        return match ($this) {
            self::Manual => null,
            self::Every15Minutes => 15,
            self::Hourly => 60,
            self::Every6Hours => 360,
            self::Every12Hours => 720,
            self::Daily => 1440,
        };
    }
}
