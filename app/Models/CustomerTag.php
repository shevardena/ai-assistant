<?php

namespace App\Models;

use Database\Factories\CustomerTagFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class CustomerTag extends Model
{
    /** @use HasFactory<CustomerTagFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['team_id', 'name', 'slug'];

    protected static function booted(): void
    {
        static::saving(function (CustomerTag $tag): void {
            $tag->name = trim($tag->name);
            $tag->slug = Str::slug($tag->name);
        });
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsToMany<Customer, $this> */
    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'customer_customer_tag');
    }
}
