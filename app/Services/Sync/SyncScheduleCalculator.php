<?php

namespace App\Services\Sync;

use App\Enums\ApiOperationSyncFrequency;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class SyncScheduleCalculator
{
    public function nextRunAt(ApiOperationSyncFrequency $frequency, CarbonInterface $from): ?CarbonImmutable
    {
        $minutes = $frequency->minutes();

        if ($minutes === null) {
            return null;
        }

        $next = CarbonImmutable::instance($from)->addMinutes($minutes);

        $current = CarbonImmutable::now($from->getTimezone());

        while ($next->lessThanOrEqualTo($current)) {
            $next = $next->addMinutes($minutes);
        }

        return $next;
    }

    public function nextFromNow(ApiOperationSyncFrequency $frequency): ?CarbonImmutable
    {
        return $this->nextRunAt($frequency, now());
    }
}
