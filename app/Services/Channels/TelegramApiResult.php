<?php

namespace App\Services\Channels;

final readonly class TelegramApiResult
{
    public function __construct(
        public bool $successful,
        public ?TelegramBotProfile $bot = null,
        public ?string $providerMessageReference = null,
        public ?string $errorCode = null,
    ) {}

    public static function success(
        ?TelegramBotProfile $bot = null,
        ?string $providerMessageReference = null,
    ): self {
        return new self(true, $bot, $providerMessageReference);
    }

    public static function failure(string $errorCode): self
    {
        return new self(false, null, null, $errorCode);
    }
}
