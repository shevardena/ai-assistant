<?php

namespace App\Models;

use Database\Factories\CustomerCustomFieldFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerCustomField extends Model
{
    /** @use HasFactory<CustomerCustomFieldFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['team_id', 'key', 'label', 'type', 'required', 'active', 'sort_order', 'options'];

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return HasMany<CustomerCustomFieldValue, $this> */
    public function values(): HasMany
    {
        return $this->hasMany(CustomerCustomFieldValue::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['required' => 'boolean', 'active' => 'boolean', 'sort_order' => 'integer', 'options' => 'array'];
    }
}
