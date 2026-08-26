<?php

namespace App\Services\Customers;

use App\Enums\CustomerActivityType;
use App\Models\Customer;
use App\Models\CustomerActivity;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final class CustomerActivityService
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function record(
        Team $team,
        Customer $customer,
        CustomerActivityType|string $type,
        string $title,
        ?string $description = null,
        ?User $actor = null,
        ?Model $related = null,
        ?string $relatedUrl = null,
        ?array $metadata = null,
    ): CustomerActivity {
        $customer = $team->customers()->whereKey($customer->getKey())->firstOrFail();

        return $customer->activities()->create([
            'team_id' => $team->getKey(),
            'actor_id' => $actor?->getKey(),
            'type' => $type instanceof CustomerActivityType ? $type->value : $type,
            'title' => $title,
            'description' => $description,
            'occurred_at' => now(),
            'related_type' => $related?->getMorphClass(),
            'related_id' => $related?->getKey(),
            'related_url' => $relatedUrl,
            'metadata' => $metadata,
        ]);
    }
}
