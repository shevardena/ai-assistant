<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\ConversationState;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConversationState>
 */
class ConversationStateFactory extends Factory
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
            'active_search' => null,
            'last_result_ids' => [],
            'memory' => [],
            'version' => 1,
        ];
    }
}
