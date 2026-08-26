<?php

namespace Database\Factories;

use App\Models\Bot;
use App\Models\WidgetVisitor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WidgetVisitor>
 */
class WidgetVisitorFactory extends Factory
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
            'public_id' => (string) Str::uuid(),
            'external_customer_id' => null,
            'attributes' => [],
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
