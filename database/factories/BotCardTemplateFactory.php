<?php

namespace Database\Factories;

use App\Models\Bot;
use App\Models\BotCardTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BotCardTemplate>
 */
class BotCardTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bot_id' => Bot::factory(),
            'dataset_id' => null,
            'api_operation_id' => null,
            'name' => 'Default',
            'is_default' => false,
            'mapping' => [
                'title' => 'name',
            ],
            'layout' => [],
        ];
    }
}
