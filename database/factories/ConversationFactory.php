<?php

namespace Database\Factories;

use App\Enums\ConversationChannel;
use App\Enums\ConversationHandoffStatus;
use App\Models\Bot;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::uuid(),
            'bot_id' => Bot::factory(),
            'channel_connection_id' => null,
            'visitor_id' => null,
            'channel' => ConversationChannel::Website->value,
            'external_user_reference' => null,
            'external_conversation_reference' => null,
            'status' => 'active',
            'conversation_status' => 'open',
            'handoff_status' => ConversationHandoffStatus::Ai->value,
            'handoff_reason' => null,
            'handoff_requested_at' => null,
            'handoff_started_at' => null,
            'handoff_user_id' => null,
            'language' => 'en',
            'openai_conversation_id' => null,
            'summary' => null,
            'metadata' => [],
            'last_message_at' => now(),
        ];
    }
}
