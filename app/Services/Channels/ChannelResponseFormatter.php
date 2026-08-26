<?php

namespace App\Services\Channels;

use App\Data\ChannelOutboundMessage;
use App\Enums\ConversationChannel;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Conversations\Blocks\ConversationBlockNormalizer;
use App\Services\Conversations\ConversationReply;

final class ChannelResponseFormatter
{
    public function __construct(
        private readonly WhatsAppTextFormatter $whatsappText,
        private readonly ConversationBlockNormalizer $blockNormalizer,
    ) {}

    public function format(ConversationReply $reply): ChannelOutboundMessage
    {
        $channel = $reply->conversation->channel;

        if (in_array($channel, [
            ConversationChannel::WhatsApp,
            ConversationChannel::Instagram,
            ConversationChannel::FacebookMessenger,
            ConversationChannel::Telegram,
            ConversationChannel::Sms,
            ConversationChannel::Email,
        ], true)) {
            return new ChannelOutboundMessage(
                channel: $channel,
                text: $this->whatsappText->format((string) $reply->assistantMessage->content, $reply->blocks, $reply->cards),
                blocks: [],
                cards: [],
                externalUserId: $reply->conversation->external_user_reference,
                metadata: $this->emailMetadata($reply->conversation, $reply->assistantMessage),
            );
        }

        return new ChannelOutboundMessage(
            channel: $channel,
            text: (string) $reply->assistantMessage->content,
            blocks: $reply->blocks,
            cards: $reply->cards,
        );
    }

    public function formatMessage(Conversation $conversation, Message $message): ChannelOutboundMessage
    {
        $blocks = $this->blockNormalizer->forMessage($message);

        return new ChannelOutboundMessage(
            channel: $conversation->channel,
            text: in_array($conversation->channel, [
                ConversationChannel::WhatsApp,
                ConversationChannel::Instagram,
                ConversationChannel::FacebookMessenger,
                ConversationChannel::Telegram,
                ConversationChannel::Sms,
                ConversationChannel::Email,
            ], true)
                ? $this->whatsappText->format((string) $message->content, $blocks)
                : (string) $message->content,
            blocks: $conversation->channel === ConversationChannel::Website ? $blocks : [],
            externalUserId: $conversation->external_user_reference,
            metadata: $this->emailMetadata($conversation, $message),
        );
    }

    /** @return array<string, mixed> */
    private function emailMetadata(Conversation $conversation, Message $message): array
    {
        if ($conversation->channel !== ConversationChannel::Email) {
            return [];
        }

        $host = parse_url((string) config('app.url', 'https://example.test'), PHP_URL_HOST) ?: 'example.test';

        return [
            'email_subject' => data_get($conversation->metadata, 'email_subject'),
            'email_in_reply_to' => data_get($conversation->metadata, 'email_last_inbound_message_id'),
            'email_references' => data_get($conversation->metadata, 'email_thread_references', []),
            'email_message_id' => '<assistant-'.$message->getKey().'.'.$conversation->public_id.'@'.$host.'>',
        ];
    }
}
