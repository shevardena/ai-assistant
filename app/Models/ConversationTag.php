<?php

namespace App\Models;

use Database\Factories\ConversationTagFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class ConversationTag extends Model
{
    /** @use HasFactory<ConversationTagFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(function (ConversationTag $tag): void {
            $tag->public_id ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsToMany<Conversation, $this> */
    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class, 'conversation_tag');
    }
}
