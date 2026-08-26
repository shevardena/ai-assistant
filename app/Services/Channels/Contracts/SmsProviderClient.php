<?php

namespace App\Services\Channels\Contracts;

use App\Services\Channels\SmsProviderResult;

interface SmsProviderClient
{
    public function validate(string $accountSid, string $authToken, string $phoneNumber): SmsProviderResult;

    public function send(
        string $accountSid,
        string $authToken,
        string $from,
        string $to,
        string $body,
    ): SmsProviderResult;
}
