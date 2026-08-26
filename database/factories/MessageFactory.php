<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'channel_connection_id' => null,
            'external_message_reference' => null,
            'role' => fake()->randomElement(['user', 'assistant']),
            'type' => 'text',
            'content' => fake()->sentence(),
            'tool_name' => null,
            'tool_call_id' => null,
            'tool_calls' => null,
            'tool_result' => null,
            'metadata' => [],
            'input_tokens' => fake()->numberBetween(0, 100),
            'output_tokens' => fake()->numberBetween(0, 100),
        ];
    }
}
