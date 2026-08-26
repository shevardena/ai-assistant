<?php

namespace Database\Factories;

use App\Enums\ChannelConnectionStatus;
use App\Enums\ConversationChannel;
use App\Models\Bot;
use App\Models\ChannelConnection;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ChannelConnection>
 */
class ChannelConnectionFactory extends Factory
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
            'team_id' => Team::factory(),
            'bot_id' => Bot::factory(),
            'channel' => ConversationChannel::Website->value,
            'name' => 'Website',
            'status' => ChannelConnectionStatus::Draft->value,
            'configuration' => [
                'managed_by' => 'website_widget',
                'domains_source' => 'bot_domains',
                'provisioned_by' => 'factory',
            ],
        ];
    }
}
