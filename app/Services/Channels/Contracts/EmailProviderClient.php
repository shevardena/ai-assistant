<?php

namespace App\Services\Channels\Contracts;

use App\Services\Channels\EmailProviderResult;

interface EmailProviderClient
{
    public function validate(string $serverToken, string $fromAddress): EmailProviderResult;

    /** @param array<string, mixed> $message */
    public function send(string $serverToken, array $message): EmailProviderResult;
}
