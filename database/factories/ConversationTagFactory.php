<?php

namespace Database\Factories;

use App\Models\ConversationTag;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ConversationTag> */
class ConversationTagFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = implode(' ', (array) fake()->unique()->words(2));

        return [
            'team_id' => Team::factory(),
            'name' => Str::title($name),
            'slug' => Str::slug($name),
        ];
    }
}
