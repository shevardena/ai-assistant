<?php

namespace App\Services\Channels;

use App\Enums\ChannelConnectionStatus;
use App\Enums\ConversationChannel;
use App\Models\Conversation;
use App\Models\Message;

final class ChannelDeliveryService
{
    public function __construct(
        private readonly WhatsAppChannelAdapter $whatsapp,
        private readonly InstagramChannelAdapter $instagram,
        private readonly FacebookMessengerChannelAdapter $facebookMessenger,
        private readonly TelegramChannelAdapter $telegram,
        private readonly TwilioSmsChannelAdapter $sms,
        private readonly PostmarkEmailChannelAdapter $email,
        private readonly ChannelResponseFormatter $formatter,
    ) {}

    public function sendAssistantMessage(Conversation $conversation, Message $message): ChannelDeliveryResult
    {
        $adapter = match ($conversation->channel) {
            ConversationChannel::WhatsApp => $this->whatsapp,
            ConversationChannel::Instagram => $this->instagram,
            ConversationChannel::FacebookMessenger => $this->facebookMessenger,
            ConversationChannel::Telegram => $this->telegram,
            ConversationChannel::Sms => $this->sms,
            ConversationChannel::Email => $this->email,
            default => null,
        };

        if ($adapter === null) {
            return ChannelDeliveryResult::success();
        }

        $connection = $conversation->channelConnection()->with('credential')->first();

        if ($connection === null || $connection->status->value !== 'active') {
            $errorCode = match ($conversation->channel) {
                ConversationChannel::WhatsApp => 'whatsapp_unavailable',
                ConversationChannel::Telegram => 'telegram_unavailable',
                ConversationChannel::Sms => 'sms_provider_unavailable',
                ConversationChannel::Email => 'email_provider_unavailable',
                default => 'meta_unavailable',
            };

            return $this->mark($message, ChannelDeliveryResult::failure($errorCode));
        }

        $result = $adapter->send($connection, $this->formatter->formatMessage($conversation, $message));

        if (! $result->successful) {
            $connection->update(['status' => ChannelConnectionStatus::Error]);
        }

        return $this->mark($message, $result);
    }

    private function mark(Message $message, ChannelDeliveryResult $result): ChannelDeliveryResult
    {
        $rawMetadata = $message->getAttribute('metadata');
        $metadata = is_array($rawMetadata) ? $rawMetadata : [];
        $metadata['delivery_status'] = $result->successful ? 'sent' : 'failed';
        $metadata = [...$metadata, ...$result->metadata];

        if ($result->successful && $result->providerMessageReference !== null) {
            $metadata['provider_message_reference'] = $result->providerMessageReference;
        }

        if (! $result->successful && $result->errorCode !== null) {
            $metadata['delivery_error_code'] = $result->errorCode;
        }

        $message->update(['metadata' => $metadata]);

        if ($result->successful && isset($result->metadata['external_message_reference'])) {
            $message->update([
                'external_message_reference' => $result->metadata['external_message_reference'],
            ]);
        }

        return $result;
    }
}
