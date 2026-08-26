<?php

namespace App\Services\Channels;

final readonly class EmailProviderResult
{
    public function __construct(
        public bool $successful,
        public ?string $providerMessageReference = null,
        public ?string $errorCode = null,
        public bool $senderVerified = false,
        public ?string $providerAccountReference = null,
    ) {}

    public static function success(
        ?string $providerMessageReference = null,
        bool $senderVerified = false,
        ?string $providerAccountReference = null,
    ): self {
        return new self(true, $providerMessageReference, null, $senderVerified, $providerAccountReference);
    }

    public static function failure(string $errorCode): self
    {
        return new self(false, null, $errorCode);
    }
}
