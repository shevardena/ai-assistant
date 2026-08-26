<?php

namespace App\Services\Channels;

use App\Services\Channels\Contracts\SmsProviderClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class TwilioSmsClient implements SmsProviderClient
{
    public function __construct(private readonly SmsProviderErrorNormalizer $errors) {}

    public function validate(string $accountSid, string $authToken, string $phoneNumber): SmsProviderResult
    {
        try {
            $response = $this->request($accountSid, $authToken)
                ->get($this->incomingNumbersUrl($accountSid), ['PhoneNumber' => $phoneNumber]);

            if (! $response->successful()) {
                return $this->failure($response);
            }

            $numbers = $response->json('incoming_phone_numbers');

            if (! is_array($numbers)) {
                return SmsProviderResult::failure('sms_provider_unavailable');
            }

            foreach ($numbers as $number) {
                if (! is_array($number) || ($number['phone_number'] ?? null) !== $phoneNumber) {
                    continue;
                }

                return SmsProviderResult::success(
                    providerChannelReference: is_scalar($number['sid'] ?? null) ? (string) $number['sid'] : null,
                    displayName: is_string($number['friendly_name'] ?? null) ? $number['friendly_name'] : null,
                );
            }

            return SmsProviderResult::failure('sms_invalid_sender');
        } catch (ConnectionException) {
            return SmsProviderResult::failure('sms_timeout');
        }
    }

    public function send(
        string $accountSid,
        string $authToken,
        string $from,
        string $to,
        string $body,
    ): SmsProviderResult {
        try {
            $response = $this->request($accountSid, $authToken)
                ->asForm()
                ->post($this->messagesUrl($accountSid), [
                    'To' => $to,
                    'From' => $from,
                    'Body' => $body,
                ]);

            if (! $response->successful()) {
                return $this->failure($response);
            }

            $reference = $response->json('sid');

            return SmsProviderResult::success(
                providerMessageReference: is_scalar($reference) ? (string) $reference : null,
            );
        } catch (ConnectionException) {
            return SmsProviderResult::failure('sms_timeout');
        }
    }

    private function failure(Response $response): SmsProviderResult
    {
        $payload = $response->json();

        return SmsProviderResult::failure($this->errors->normalize(
            $response->status(),
            is_array($payload) ? $payload : null,
        ));
    }

    private function request(string $accountSid, string $authToken): PendingRequest
    {
        return Http::withBasicAuth($accountSid, $authToken)
            ->acceptJson()
            ->timeout((int) config('services.twilio.sms.timeout', 8))
            ->connectTimeout((int) config('services.twilio.sms.connect_timeout', 3));
    }

    private function incomingNumbersUrl(string $accountSid): string
    {
        return $this->apiUrl().'/2010-04-01/Accounts/'.rawurlencode($accountSid).'/IncomingPhoneNumbers.json';
    }

    private function messagesUrl(string $accountSid): string
    {
        return $this->apiUrl().'/2010-04-01/Accounts/'.rawurlencode($accountSid).'/Messages.json';
    }

    private function apiUrl(): string
    {
        return rtrim((string) config('services.twilio.sms.api_url', 'https://api.twilio.com'), '/');
    }
}
