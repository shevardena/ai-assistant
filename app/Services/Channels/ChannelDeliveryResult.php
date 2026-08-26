<?php

namespace App\Services\Channels;

final readonly class ChannelDeliveryResult
{
    public function __construct(
        public bool $successful,
        public ?string $providerMessageReference = null,
        public ?string $errorCode = null,
        /** @var array<string, mixed> */
        public array $metadata = [],
    ) {}

    /** @param array<string, mixed> $metadata */
    public static function success(?string $providerMessageReference = null, array $metadata = []): self
    {
        return new self(true, $providerMessageReference, null, $metadata);
    }

    public static function failure(string $errorCode): self
    {
        return new self(false, null, $errorCode);
    }
}
