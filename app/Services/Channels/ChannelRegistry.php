<?php

namespace App\Services\Channels;

use App\Data\ChannelDefinition;
use App\Enums\ChannelCapability;
use App\Enums\ConversationChannel;

final class ChannelRegistry
{
    /**
     * @return list<ChannelDefinition>
     */
    public function all(): array
    {
        return [
            new ChannelDefinition(
                key: ConversationChannel::Website,
                name: 'Website',
                description: 'Deploy the assistant through the existing website widget and domain settings.',
                implemented: true,
                capabilities: [
                    ChannelCapability::Text,
                    ChannelCapability::Buttons,
                    ChannelCapability::Images,
                    ChannelCapability::ProductCards,
                    ChannelCapability::Forms,
                    ChannelCapability::Comparison,
                    ChannelCapability::OrderStatus,
                    ChannelCapability::Tracking,
                    ChannelCapability::Locations,
                    ChannelCapability::Confirmation,
                    ChannelCapability::AppointmentSlots,
                ],
            ),
            new ChannelDefinition(
                key: ConversationChannel::WhatsApp,
                name: 'WhatsApp',
                description: 'Connect a WhatsApp Business number through Meta Cloud API.',
                implemented: true,
                capabilities: [ChannelCapability::Text, ChannelCapability::Confirmation, ChannelCapability::ProductCards, ChannelCapability::OrderStatus, ChannelCapability::Locations, ChannelCapability::AppointmentSlots],
            ),
            new ChannelDefinition(
                key: ConversationChannel::Instagram,
                name: 'Instagram',
                description: 'Connect Instagram Direct Messaging through Meta.',
                implemented: true,
                capabilities: [ChannelCapability::Text, ChannelCapability::Confirmation, ChannelCapability::ProductCards, ChannelCapability::Comparison, ChannelCapability::OrderStatus, ChannelCapability::Tracking, ChannelCapability::Locations, ChannelCapability::AppointmentSlots],
            ),
            new ChannelDefinition(
                key: ConversationChannel::FacebookMessenger,
                name: 'Facebook Messenger',
                description: 'Connect Facebook Messenger through Meta Pages.',
                implemented: true,
                capabilities: [ChannelCapability::Text, ChannelCapability::Confirmation, ChannelCapability::ProductCards, ChannelCapability::Comparison, ChannelCapability::OrderStatus, ChannelCapability::Tracking, ChannelCapability::Locations, ChannelCapability::AppointmentSlots],
            ),
            new ChannelDefinition(
                key: ConversationChannel::Telegram,
                name: 'Telegram',
                description: 'Connect a Telegram Bot for private customer conversations.',
                implemented: true,
                capabilities: [ChannelCapability::Text, ChannelCapability::Confirmation, ChannelCapability::ProductCards, ChannelCapability::Comparison, ChannelCapability::OrderStatus, ChannelCapability::Tracking, ChannelCapability::Locations, ChannelCapability::AppointmentSlots],
            ),
            new ChannelDefinition(
                key: ConversationChannel::Sms,
                name: 'SMS',
                description: 'Connect one-to-one support SMS through Twilio.',
                implemented: true,
                capabilities: [ChannelCapability::Text, ChannelCapability::Confirmation, ChannelCapability::ProductCards, ChannelCapability::Comparison, ChannelCapability::OrderStatus, ChannelCapability::Tracking, ChannelCapability::Locations, ChannelCapability::AppointmentSlots],
            ),
            new ChannelDefinition(
                key: ConversationChannel::Email,
                name: 'Email',
                description: 'Connect one-to-one support email through Postmark.',
                implemented: true,
                capabilities: [ChannelCapability::Text, ChannelCapability::ProductCards, ChannelCapability::Comparison, ChannelCapability::OrderStatus, ChannelCapability::Tracking, ChannelCapability::Locations, ChannelCapability::Confirmation, ChannelCapability::AppointmentSlots, ChannelCapability::FileAttachments],
            ),
        ];
    }

    /**
     * @return list<ChannelDefinition>
     */
    public function implemented(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (ChannelDefinition $definition): bool => $definition->implemented,
        ));
    }

    public function find(ConversationChannel $channel): ?ChannelDefinition
    {
        foreach ($this->all() as $definition) {
            if ($definition->key === $channel) {
                return $definition;
            }
        }

        return null;
    }
}
