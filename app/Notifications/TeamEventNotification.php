<?php

namespace App\Notifications;

use App\Enums\TeamNotificationType;
use App\Notifications\Channels\TeamDatabaseChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

final class TeamEventNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array{bot_name?: string|null, target_type?: string|null, target_reference?: string|int|null, occurred_at?: string|null}  $data
     */
    public function __construct(
        public readonly TeamNotificationType $notificationType,
        public readonly int $teamId,
        public readonly string $eventKey,
        public readonly string $title,
        public readonly string $message,
        public readonly array $data = [],
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return list<class-string>
     */
    public function via(object $notifiable): array
    {
        return [TeamDatabaseChannel::class];
    }

    public function databaseType(object $notifiable): string
    {
        return self::class;
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => $this->notificationType->value,
            'title' => $this->title,
            'message' => $this->message,
            'bot_name' => $this->data['bot_name'] ?? null,
            'target_type' => $this->data['target_type'] ?? null,
            'target_reference' => $this->data['target_reference'] ?? null,
            'occurred_at' => $this->data['occurred_at'] ?? null,
        ];
    }
}
