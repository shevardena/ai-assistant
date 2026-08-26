<?php

namespace Database\Factories;

use App\Models\ChannelConnection;
use App\Models\ChannelCredential;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChannelCredential>
 */
class ChannelCredentialFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $token = fake()->sha256();

        return [
            'team_id' => Team::factory(),
            'channel_connection_id' => ChannelConnection::factory(),
            'created_by_user_id' => null,
            'provider' => 'whatsapp',
            'encrypted_access_token' => $token,
            'encrypted_verify_token' => fake()->sha256(),
            'encrypted_app_secret' => fake()->sha256(),
            'verify_token_hash' => hash('sha256', fake()->sha256()),
            'access_token_last_four' => substr($token, -4),
        ];
    }
}
