<?php

namespace Database\Factories;

use App\Enums\KnowledgeGapReason;
use App\Enums\KnowledgeGapStatus;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\KnowledgeGap;
use App\Models\Message;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<KnowledgeGap>
 */
class KnowledgeGapFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $question = Str::lower(fake()->sentence());
        $hash = hash('sha256', $question);

        return [
            'team_id' => Team::factory(),
            'bot_id' => Bot::factory(),
            'conversation_id' => Conversation::factory(),
            'message_id' => Message::factory(),
            'resolved_by' => null,
            'reason' => KnowledgeGapReason::NoResults->value,
            'normalized_question' => $question,
            'normalized_hash' => $hash,
            'group_reference' => hash('sha256', 'bot|'.$hash),
            'status' => KnowledgeGapStatus::Open->value,
            'resolved_at' => null,
        ];
    }
}
