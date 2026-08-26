<?php

namespace App\Models;

use Database\Factories\ChannelMessageReceiptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelMessageReceipt extends Model
{
    /** @use HasFactory<ChannelMessageReceiptFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<ChannelConnection, $this> */
    public function channelConnection(): BelongsTo
    {
        return $this->belongsTo(ChannelConnection::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
        ];
    }
}
