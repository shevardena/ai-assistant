<?php

namespace App\Models;

use App\Enums\ChannelConnectionStatus;
use App\Enums\ConversationChannel;
use Database\Factories\ChannelConnectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property ConversationChannel $channel
 * @property ChannelConnectionStatus $status
 */
class ChannelConnection extends Model
{
    /** @use HasFactory<ChannelConnectionFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<Bot, $this> */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    /** @return HasOne<ChannelCredential, $this> */
    public function credential(): HasOne
    {
        return $this->hasOne(ChannelCredential::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'channel' => ConversationChannel::class,
            'status' => ChannelConnectionStatus::class,
            'configuration' => 'array',
        ];
    }
}
