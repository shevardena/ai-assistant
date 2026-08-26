<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Database\Factories\TeamSubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $team_id
 * @property string $plan_key
 * @property SubscriptionStatus $status
 * @property Carbon|null $current_period_start
 * @property Carbon|null $current_period_end
 * @property string|null $provider
 * @property string|null $provider_customer_id
 * @property string|null $provider_subscription_id
 * @property string|null $provider_price_id
 * @property string|null $provider_subscription_item_id
 * @property bool $cancel_at_period_end
 */
class TeamSubscription extends Model
{
    /** @use HasFactory<TeamSubscriptionFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'cancel_at_period_end' => 'boolean',
        ];
    }
}
