<?php

namespace Database\Factories;

use App\Models\Bot;
use App\Models\SearchRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SearchRun>
 */
class SearchRunFactory extends Factory
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
            'conversation_id' => null,
            'message_id' => null,
            'dataset_id' => null,
            'query' => fake()->sentence(),
            'intent' => [],
            'adapter' => null,
            'request_payload' => [],
            'result_count' => fake()->numberBetween(0, 10),
            'latency_ms' => fake()->numberBetween(10, 1000),
            'status' => 'completed',
            'error' => null,
        ];
    }
}
