<?php

use App\Models\TeamInvitation;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    TeamInvitation::query()
        ->whereNotNull('expires_at')
        ->where('expires_at', '<', now())
        ->delete();
})->daily()->description('Delete expired team invitations');

Schedule::command('api-operations:dispatch-due-syncs')
    ->everyMinute()
    ->withoutOverlapping()
    ->description('Dispatch due synchronized API operations');
