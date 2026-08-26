<?php

namespace App\Models;

use Database\Factories\BotApiOperationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotApiOperation extends Model
{
    /** @use HasFactory<BotApiOperationFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * Get the bot for this API operation attachment.
     *
     * @return BelongsTo<Bot, $this>
     */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    /**
     * Get the API operation attached to this bot.
     *
     * @return BelongsTo<ApiOperation, $this>
     */
    public function apiOperation(): BelongsTo
    {
        return $this->belongsTo(ApiOperation::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'settings' => 'array',
        ];
    }
}
