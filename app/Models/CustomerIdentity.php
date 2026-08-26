<?php

namespace App\Models;

use Database\Factories\CustomerIdentityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerIdentity extends Model
{
    /** @use HasFactory<CustomerIdentityFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['team_id', 'customer_id', 'type', 'value', 'normalized_value', 'provider', 'provider_external_id', 'is_primary', 'is_verified'];

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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'is_verified' => 'boolean'];
    }
}
