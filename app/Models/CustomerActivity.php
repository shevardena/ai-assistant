<?php

namespace App\Models;

use Database\Factories\CustomerActivityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerActivity extends Model
{
    /** @use HasFactory<CustomerActivityFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['team_id', 'customer_id', 'actor_id', 'type', 'title', 'description', 'occurred_at', 'related_type', 'related_id', 'related_url', 'metadata'];

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'related_id' => 'integer', 'metadata' => 'array'];
    }
}
