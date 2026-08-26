<?php

namespace App\Services\Channels;

final readonly class TelegramBotProfile
{
    public function __construct(
        public int $id,
        public string $firstName,
        public ?string $lastName,
        public ?string $username,
    ) {}

    public function displayName(): string
    {
        return trim($this->firstName.' '.($this->lastName ?? ''));
    }
}
