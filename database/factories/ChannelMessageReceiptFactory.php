<?php

namespace Database\Factories;

use App\Models\ChannelConnection;
use App\Models\ChannelMessageReceipt;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChannelMessageReceipt>
 */
class ChannelMessageReceiptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'channel_connection_id' => ChannelConnection::factory(),
            'external_message_reference' => fake()->uuid(),
            'status' => 'received',
            'processed_at' => null,
        ];
    }
}
