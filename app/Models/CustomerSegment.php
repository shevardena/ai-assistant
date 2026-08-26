<?php

namespace App\Models;

use Database\Factories\CustomerSegmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerSegment extends Model
{
    /** @use HasFactory<CustomerSegmentFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['team_id', 'name', 'description', 'filter_definition', 'created_by_user_id'];

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['filter_definition' => 'array'];
    }
}
