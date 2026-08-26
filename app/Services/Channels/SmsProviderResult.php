<?php

namespace App\Services\Channels;

final readonly class SmsProviderResult
{
    public function __construct(
        public bool $successful,
        public ?string $providerMessageReference = null,
        public ?string $providerChannelReference = null,
        public ?string $displayName = null,
        public ?string $errorCode = null,
    ) {}

    public static function success(
        ?string $providerMessageReference = null,
        ?string $providerChannelReference = null,
        ?string $displayName = null,
    ): self {
        return new self(true, $providerMessageReference, $providerChannelReference, $displayName);
    }

    public static function failure(string $errorCode): self
    {
        return new self(false, null, null, null, $errorCode);
    }
}
