<?php

namespace App\Jobs;

use App\Models\ApiOperationSyncSchedule;
use App\Services\Sync\ApiOperationSyncScheduleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunApiOperationSync implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $scheduleId) {}

    public function handle(ApiOperationSyncScheduleService $service): void
    {
        $schedule = ApiOperationSyncSchedule::query()->find($this->scheduleId);

        if ($schedule === null) {
            return;
        }

        $service->run($schedule);
    }
}
