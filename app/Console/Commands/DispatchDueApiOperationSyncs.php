<?php

namespace App\Console\Commands;

use App\Jobs\RunApiOperationSync;
use App\Services\Sync\ApiOperationSyncScheduleService;
use Illuminate\Console\Command;

class DispatchDueApiOperationSyncs extends Command
{
    protected $signature = 'api-operations:dispatch-due-syncs {--limit=100}';

    protected $description = 'Dispatch due synchronized API operation imports';

    public function handle(ApiOperationSyncScheduleService $service): int
    {
        foreach ($service->claimDue((int) $this->option('limit')) as $schedule) {
            RunApiOperationSync::dispatch($schedule->id);
        }

        return self::SUCCESS;
    }
}
