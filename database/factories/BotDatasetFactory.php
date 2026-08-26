<?php

namespace Database\Factories;

use App\Models\Bot;
use App\Models\BotDataset;
use App\Models\Dataset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BotDataset>
 */
class BotDatasetFactory extends Factory
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
            'dataset_id' => Dataset::factory(),
            'priority' => 0,
            'is_enabled' => true,
            'settings' => [],
        ];
    }
}
