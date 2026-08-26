<?php

namespace App\Services\Channels;

use App\Enums\ChannelConnectionStatus;
use App\Enums\ConversationChannel;
use App\Models\Bot;
use App\Models\ChannelConnection;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ChannelConnectionService
{
    public function __construct(
        private readonly TelegramChannelAdapter $telegram,
        private readonly TwilioSmsChannelAdapter $sms,
        private readonly PostmarkEmailChannelAdapter $email,
    ) {}

    public function ensureWebsite(Bot $bot): ChannelConnection
    {
        return DB::transaction(function () use ($bot): ChannelConnection {
            $lockedBot = Bot::query()
                ->whereKey($bot->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $connection = $lockedBot->channelConnections()
                ->where('channel', ConversationChannel::Website->value)
                ->first();

            if ($connection === null) {
                $connection = $lockedBot->channelConnections()->create([
                    'team_id' => $lockedBot->team_id,
                    'public_id' => (string) Str::uuid(),
                    'channel' => ConversationChannel::Website,
                    'name' => 'Website',
                    'status' => ChannelConnectionStatus::Draft,
                    'configuration' => [
                        'managed_by' => 'website_widget',
                        'domains_source' => 'bot_domains',
                        'provisioned_by' => 'application',
                    ],
                ]);
            }

            $status = $lockedBot->domains()->active()->exists()
                ? ChannelConnectionStatus::Active
                : ChannelConnectionStatus::Draft;

            if ($connection->status !== $status) {
                $connection->update(['status' => $status]);
            }

            return $connection->fresh() ?? $connection;
        });
    }

    /**
     * @param  array<string, string|null>  $values
     */
    public function configureWhatsApp(Bot $bot, array $values, User $user): ChannelConnection
    {
        return DB::transaction(function () use ($bot, $values, $user): ChannelConnection {
            $lockedBot = Bot::query()->whereKey($bot->getKey())->lockForUpdate()->firstOrFail();
            $connection = $lockedBot->channelConnections()
                ->where('channel', ConversationChannel::WhatsApp->value)
                ->lockForUpdate()
                ->first();

            if ($connection === null) {
                $connection = $lockedBot->channelConnections()->create([
                    'team_id' => $lockedBot->team_id,
                    'public_id' => (string) Str::uuid(),
                    'channel' => ConversationChannel::WhatsApp,
                    'name' => 'WhatsApp',
                    'status' => ChannelConnectionStatus::Draft,
                ]);
            }

            $credential = $connection->credential()->first();
            $accessToken = $this->valueOrExisting($values['access_token'] ?? null, $credential?->encrypted_access_token);
            $verifyToken = $this->valueOrExisting($values['webhook_verify_token'] ?? null, $credential?->encrypted_verify_token);
            $appSecret = $this->valueOrExisting($values['app_secret'] ?? null, $credential?->encrypted_app_secret);

            if ($accessToken === null || $accessToken === '' || $verifyToken === null || $appSecret === null || $appSecret === '') {
                throw ValidationException::withMessages([
                    'access_token' => 'An access token, webhook verify token, and Meta app secret are required for a verified WhatsApp connection.',
                ]);
            }

            $phoneNumberId = trim((string) ($values['phone_number_id'] ?? ''));

            if ($phoneNumberId === '') {
                throw ValidationException::withMessages(['phone_number_id' => 'A phone number ID is required.']);
            }

            $connection->update([
                'provider_account_reference' => $this->nullableValue($values['business_account_id'] ?? null),
                'provider_channel_reference' => $phoneNumberId,
                'status' => ChannelConnectionStatus::Active,
                'configuration' => [
                    'display_phone_number' => $this->nullableValue($values['display_phone_number'] ?? null),
                    'verified_name' => $this->nullableValue($values['verified_name'] ?? null),
                ],
            ]);

            $connection->credential()->updateOrCreate(
                ['provider' => 'whatsapp'],
                [
                    'team_id' => $lockedBot->team_id,
                    'created_by_user_id' => $user->id,
                    'encrypted_access_token' => $accessToken,
                    'encrypted_verify_token' => $verifyToken,
                    'encrypted_app_secret' => $appSecret,
                    'verify_token_hash' => hash('sha256', $verifyToken),
                    'access_token_last_four' => Str::substr($accessToken, -4),
                ],
            );

            return $connection->fresh(['credential']) ?? $connection;
        });
    }

    public function disconnectWhatsApp(Bot $bot): void
    {
        DB::transaction(function () use ($bot): void {
            $connection = $bot->channelConnections()
                ->where('channel', ConversationChannel::WhatsApp->value)
                ->lockForUpdate()
                ->first();

            if ($connection === null) {
                return;
            }

            $connection->credential()->delete();
            $connection->update(['status' => ChannelConnectionStatus::Disabled]);
        });
    }

    /**
     * @param  array<string, string|null>  $values
     */
    public function configureMeta(Bot $bot, ConversationChannel $channel, array $values, User $user): ChannelConnection
    {
        abort_unless(in_array($channel, [
            ConversationChannel::Instagram,
            ConversationChannel::FacebookMessenger,
        ], true), 404);

        return DB::transaction(function () use ($bot, $channel, $values, $user): ChannelConnection {
            $lockedBot = Bot::query()->whereKey($bot->getKey())->lockForUpdate()->firstOrFail();
            $connection = $lockedBot->channelConnections()
                ->where('channel', $channel->value)
                ->lockForUpdate()
                ->first();

            if ($connection === null) {
                $connection = $lockedBot->channelConnections()->create([
                    'team_id' => $lockedBot->team_id,
                    'public_id' => (string) Str::uuid(),
                    'channel' => $channel,
                    'name' => $this->metaChannelName($channel),
                    'status' => ChannelConnectionStatus::Draft,
                ]);
            }

            $credential = $connection->credential()->where('provider', 'meta')->first();
            $accessToken = $this->valueOrExisting($values['access_token'] ?? null, $credential?->encrypted_access_token);
            $verifyToken = $this->valueOrExisting($values['webhook_verify_token'] ?? null, $credential?->encrypted_verify_token);
            $appSecret = $this->valueOrExisting($values['app_secret'] ?? null, $credential?->encrypted_app_secret);

            if ($accessToken === null || $accessToken === '' || $verifyToken === null || $verifyToken === '' || $appSecret === null || $appSecret === '') {
                throw ValidationException::withMessages([
                    'access_token' => 'An access token, webhook verify token, and Meta app secret are required for a verified connection.',
                ]);
            }

            $pageId = trim((string) ($values['facebook_page_id'] ?? ''));
            $channelReference = $channel === ConversationChannel::Instagram
                ? trim((string) ($values['instagram_account_id'] ?? ''))
                : $pageId;

            if ($channelReference === '') {
                throw ValidationException::withMessages([
                    $channel === ConversationChannel::Instagram ? 'instagram_account_id' : 'facebook_page_id' => $channel === ConversationChannel::Instagram
                            ? 'An Instagram account ID is required.'
                            : 'A Facebook Page ID is required.',
                ]);
            }

            if ($channel === ConversationChannel::Instagram && $pageId === '') {
                throw ValidationException::withMessages(['facebook_page_id' => 'A Facebook Page ID is required for Instagram messaging.']);
            }

            $connection->update([
                'provider_account_reference' => $pageId !== '' ? $pageId : null,
                'provider_channel_reference' => $channelReference,
                'status' => ChannelConnectionStatus::Active,
                'configuration' => $this->metaConfiguration($channel, $values),
            ]);

            $connection->credential()->updateOrCreate(
                ['provider' => 'meta'],
                [
                    'team_id' => $lockedBot->team_id,
                    'created_by_user_id' => $user->id,
                    'encrypted_access_token' => $accessToken,
                    'encrypted_verify_token' => $verifyToken,
                    'encrypted_app_secret' => $appSecret,
                    'verify_token_hash' => hash('sha256', $verifyToken),
                    'access_token_last_four' => Str::substr($accessToken, -4),
                ],
            );

            return $connection->fresh(['credential']) ?? $connection;
        });
    }

    public function disconnectMeta(Bot $bot, ConversationChannel $channel): void
    {
        abort_unless(in_array($channel, [
            ConversationChannel::Instagram,
            ConversationChannel::FacebookMessenger,
        ], true), 404);

        DB::transaction(function () use ($bot, $channel): void {
            $connection = $bot->channelConnections()
                ->where('channel', $channel->value)
                ->lockForUpdate()
                ->first();

            if ($connection === null) {
                return;
            }

            $connection->credential()->delete();
            $connection->update(['status' => ChannelConnectionStatus::Disabled]);
        });
    }

    /**
     * @param  array<string, string|null>  $values
     */
    public function configureTelegram(Bot $bot, array $values, User $user): ChannelConnection
    {
        $existing = $bot->channelConnections()
            ->where('channel', ConversationChannel::Telegram->value)
            ->with('credential')
            ->first();
        $token = $this->valueOrExisting($values['bot_token'] ?? null, $existing?->credential?->encrypted_access_token);

        if ($token === null || $token === '') {
            throw ValidationException::withMessages(['bot_token' => 'A Telegram Bot token is required.']);
        }

        $profileResult = $this->telegram->validateBot($token);

        if (! $profileResult->successful || $profileResult->bot === null) {
            throw ValidationException::withMessages([
                'bot_token' => 'Telegram could not validate this Bot token. Check the token and try again.',
            ]);
        }

        $secret = $this->valueOrExisting(
            null,
            $existing?->credential?->encrypted_verify_token,
        ) ?? Str::random(64);

        $connection = DB::transaction(function () use ($bot, $profileResult, $token, $secret, $user): ChannelConnection {
            $lockedBot = Bot::query()->whereKey($bot->getKey())->lockForUpdate()->firstOrFail();
            $connection = $lockedBot->channelConnections()
                ->where('channel', ConversationChannel::Telegram->value)
                ->lockForUpdate()
                ->first();

            if ($connection === null) {
                $connection = $lockedBot->channelConnections()->create([
                    'team_id' => $lockedBot->team_id,
                    'public_id' => (string) Str::uuid(),
                    'channel' => ConversationChannel::Telegram,
                    'name' => 'Telegram',
                    'status' => ChannelConnectionStatus::Draft,
                ]);
            }

            $connection->update([
                'provider_channel_reference' => (string) $profileResult->bot->id,
                'provider_account_reference' => null,
                'status' => ChannelConnectionStatus::Draft,
                'configuration' => [
                    'bot_id' => $profileResult->bot->id,
                    'bot_username' => $profileResult->bot->username,
                    'display_name' => $profileResult->bot->displayName(),
                    'webhook_configured' => false,
                ],
            ]);

            $connection->credential()->updateOrCreate(
                ['provider' => 'telegram'],
                [
                    'team_id' => $lockedBot->team_id,
                    'created_by_user_id' => $user->id,
                    'encrypted_access_token' => $token,
                    'encrypted_verify_token' => $secret,
                    'encrypted_app_secret' => null,
                    'verify_token_hash' => hash('sha256', $secret),
                    'access_token_last_four' => Str::substr($token, -4),
                ],
            );

            return $connection->fresh(['credential']) ?? $connection;
        });

        $webhook = $this->telegram->registerWebhook(
            $token,
            route('channels.telegram.webhook.receive', ['connection' => $connection->public_id]),
            $secret,
        );

        if (! $webhook->successful) {
            $connection->update(['status' => ChannelConnectionStatus::Error]);

            throw ValidationException::withMessages([
                'bot_token' => 'Telegram Bot validation succeeded, but webhook registration failed. Try again.',
            ]);
        }

        $configuration = $connection->getAttribute('configuration');
        $configuration = is_array($configuration) ? $configuration : [];

        $connection->update([
            'status' => ChannelConnectionStatus::Active,
            'configuration' => [
                ...$configuration,
                'webhook_configured' => true,
            ],
        ]);

        return $connection->fresh(['credential']) ?? $connection;
    }

    public function disconnectTelegram(Bot $bot): void
    {
        DB::transaction(function () use ($bot): void {
            $connection = $bot->channelConnections()
                ->where('channel', ConversationChannel::Telegram->value)
                ->with('credential')
                ->lockForUpdate()
                ->first();

            if ($connection === null) {
                return;
            }

            $token = $connection->credential?->encrypted_access_token;

            if (is_string($token) && $token !== '') {
                $this->telegram->deleteWebhook($token);
            }

            $connection->credential()->delete();
            $configuration = $connection->getAttribute('configuration');
            $configuration = is_array($configuration) ? $configuration : [];
            $connection->update([
                'status' => ChannelConnectionStatus::Disabled,
                'configuration' => [
                    ...$configuration,
                    'webhook_configured' => false,
                ],
            ]);
        });
    }

    /**
     * @param  array<string, string|null>  $values
     */
    public function configureSms(Bot $bot, array $values, User $user): ChannelConnection
    {
        $existing = $bot->channelConnections()
            ->where('channel', ConversationChannel::Sms->value)
            ->with('credential')
            ->first();
        $accountSid = $this->valueOrExisting($values['account_sid'] ?? null, $existing?->credential?->encrypted_verify_token);
        $authToken = $this->valueOrExisting($values['auth_token'] ?? null, $existing?->credential?->encrypted_access_token);
        $phoneNumber = $this->valueOrExisting($values['phone_number'] ?? null, data_get($existing?->configuration, 'phone_number'));

        if ($accountSid === null || $authToken === null || $phoneNumber === null) {
            throw ValidationException::withMessages([
                'account_sid' => 'A Twilio Account SID, Auth Token, and sending phone number are required.',
            ]);
        }

        $validation = $this->sms->validateConnection($accountSid, $authToken, $phoneNumber);

        if (! $validation->successful) {
            throw ValidationException::withMessages([
                'account_sid' => 'Twilio could not validate this account and phone number. Check the credentials and try again.',
            ]);
        }

        return DB::transaction(function () use ($bot, $values, $user, $accountSid, $authToken, $phoneNumber, $validation): ChannelConnection {
            $lockedBot = Bot::query()->whereKey($bot->getKey())->lockForUpdate()->firstOrFail();
            $connection = $lockedBot->channelConnections()
                ->where('channel', ConversationChannel::Sms->value)
                ->lockForUpdate()
                ->first();

            if ($connection === null) {
                $connection = $lockedBot->channelConnections()->create([
                    'team_id' => $lockedBot->team_id,
                    'public_id' => (string) Str::uuid(),
                    'channel' => ConversationChannel::Sms,
                    'name' => 'SMS',
                    'status' => ChannelConnectionStatus::Draft,
                ]);
            }

            $connection->update([
                'provider_channel_reference' => $validation->providerChannelReference ?? $phoneNumber,
                'provider_account_reference' => null,
                'status' => ChannelConnectionStatus::Active,
                'configuration' => [
                    'provider' => 'twilio',
                    'phone_number' => $phoneNumber,
                    'display_name' => $this->nullableValue($values['display_name'] ?? null) ?? $validation->displayName,
                ],
            ]);

            $connection->credential()->updateOrCreate(
                ['provider' => 'twilio'],
                [
                    'team_id' => $lockedBot->team_id,
                    'created_by_user_id' => $user->id,
                    'encrypted_access_token' => $authToken,
                    'encrypted_verify_token' => $accountSid,
                    'encrypted_app_secret' => null,
                    'verify_token_hash' => hash('sha256', $accountSid),
                    'access_token_last_four' => Str::substr($authToken, -4),
                ],
            );

            return $connection->fresh(['credential']) ?? $connection;
        });
    }

    public function disconnectSms(Bot $bot): void
    {
        DB::transaction(function () use ($bot): void {
            $connection = $bot->channelConnections()
                ->where('channel', ConversationChannel::Sms->value)
                ->lockForUpdate()
                ->first();

            if ($connection === null) {
                return;
            }

            $connection->credential()->delete();
            $connection->update(['status' => ChannelConnectionStatus::Disabled]);
        });
    }

    /** @param array<string, string|null> $values */
    public function configureEmail(Bot $bot, array $values, User $user): ChannelConnection
    {
        $existing = $bot->channelConnections()
            ->where('channel', ConversationChannel::Email->value)
            ->with('credential')
            ->first();
        $serverToken = $this->valueOrExisting($values['server_api_token'] ?? null, $existing?->credential?->encrypted_access_token);
        $webhookSecret = $this->valueOrExisting($values['webhook_secret'] ?? null, $existing?->credential?->encrypted_verify_token);
        $inboundAddress = $this->nullableValue($values['inbound_address'] ?? null);
        $fromAddress = $this->nullableValue($values['from_address'] ?? null);

        if ($serverToken === null || $webhookSecret === null || $inboundAddress === null || $fromAddress === null) {
            throw ValidationException::withMessages([
                'server_api_token' => 'A Postmark server token, webhook secret, inbound address, and from address are required.',
            ]);
        }

        $validation = $this->email->validateConnection($serverToken, $fromAddress);

        if (! $validation->successful) {
            throw ValidationException::withMessages([
                'server_api_token' => 'Postmark could not validate this server token. Check the token and try again.',
            ]);
        }

        return DB::transaction(function () use ($bot, $values, $user, $serverToken, $webhookSecret, $inboundAddress, $fromAddress, $validation): ChannelConnection {
            $lockedBot = Bot::query()->whereKey($bot->getKey())->lockForUpdate()->firstOrFail();
            $connection = $lockedBot->channelConnections()
                ->where('channel', ConversationChannel::Email->value)
                ->lockForUpdate()
                ->first();

            if ($connection === null) {
                $connection = $lockedBot->channelConnections()->create([
                    'team_id' => $lockedBot->team_id,
                    'public_id' => (string) Str::uuid(),
                    'channel' => ConversationChannel::Email,
                    'name' => 'Email',
                    'status' => ChannelConnectionStatus::Active,
                ]);
            }

            $connection->update([
                'provider_account_reference' => $validation->providerAccountReference,
                'provider_channel_reference' => strtolower($inboundAddress),
                'status' => ChannelConnectionStatus::Active,
                'configuration' => [
                    'provider' => 'postmark',
                    'inbound_address' => strtolower($inboundAddress),
                    'from_address' => strtolower($fromAddress),
                    'from_name' => $this->nullableValue($values['from_name'] ?? null),
                    'reply_to_address' => $this->nullableValue($values['reply_to_address'] ?? null),
                    'display_name' => $this->nullableValue($values['display_name'] ?? null),
                    'inbound_status' => 'setup_pending',
                    'webhook_url' => route('channels.email.webhook.receive', ['connection' => $connection->public_id]),
                ],
            ]);
            $connection->credential()->updateOrCreate(
                ['provider' => 'postmark'],
                [
                    'team_id' => $lockedBot->team_id,
                    'created_by_user_id' => $user->id,
                    'encrypted_access_token' => $serverToken,
                    'encrypted_verify_token' => $webhookSecret,
                    'encrypted_app_secret' => null,
                    'verify_token_hash' => hash('sha256', $webhookSecret),
                    'access_token_last_four' => Str::substr($serverToken, -4),
                ],
            );

            return $connection->fresh(['credential']) ?? $connection;
        });
    }

    public function disconnectEmail(Bot $bot): void
    {
        DB::transaction(function () use ($bot): void {
            $connection = $bot->channelConnections()
                ->where('channel', ConversationChannel::Email->value)
                ->lockForUpdate()
                ->first();

            if ($connection === null) {
                return;
            }

            $connection->credential()->delete();
            $connection->update(['status' => ChannelConnectionStatus::Disabled]);
        });
    }

    private function valueOrExisting(?string $value, mixed $existing): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : (is_string($existing) && $existing !== '' ? $existing : null);
    }

    private function nullableValue(?string $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }

    private function metaChannelName(ConversationChannel $channel): string
    {
        return $channel === ConversationChannel::Instagram ? 'Instagram' : 'Facebook Messenger';
    }

    /**
     * @param  array<string, string|null>  $values
     * @return array<string, string|null>
     */
    private function metaConfiguration(ConversationChannel $channel, array $values): array
    {
        if ($channel === ConversationChannel::Instagram) {
            return [
                'display_name' => $this->nullableValue($values['display_name'] ?? null),
                'username' => $this->nullableValue($values['username'] ?? null),
            ];
        }

        return [
            'page_name' => $this->nullableValue($values['page_name'] ?? null),
        ];
    }
}
