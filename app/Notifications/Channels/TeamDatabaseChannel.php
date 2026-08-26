<?php

namespace App\Notifications\Channels;

use App\Notifications\TeamEventNotification;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;

final class TeamDatabaseChannel extends DatabaseChannel
{
    /**
     * @return array<string, mixed>
     */
    protected function buildPayload($notifiable, Notification $notification): array
    {
        $payload = parent::buildPayload($notifiable, $notification);

        if ($notification instanceof TeamEventNotification) {
            $payload['team_id'] = $notification->teamId;
            $payload['event_key'] = $notification->eventKey;
        }

        return $payload;
    }
}
