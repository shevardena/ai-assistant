<?php

namespace App\Services\Channels;

final readonly class MetaGraphResult
{
    public function __construct(
        public bool $successful,
        public ?string $providerMessageReference = null,
        public ?string $errorCode = null,
    ) {}

    public static function success(?string $providerMessageReference = null): self
    {
        return new self(true, $providerMessageReference);
    }

    public static function failure(string $errorCode): self
    {
        return new self(false, null, $errorCode);
    }
}
