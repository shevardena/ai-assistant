<?php

namespace App\Models;

use Database\Factories\CustomerFactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerFact extends Model
{
    /** @use HasFactory<CustomerFactFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['team_id', 'customer_id', 'key', 'value', 'value_type', 'source', 'confidence', 'last_confirmed_at', 'created_by_user_id'];

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
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['confidence' => 'decimal:4', 'last_confirmed_at' => 'datetime'];
    }
}
