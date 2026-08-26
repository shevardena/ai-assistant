<?php

namespace App\Models;

use Database\Factories\ChannelCredentialFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelCredential extends Model
{
    /** @use HasFactory<ChannelCredentialFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = [
        'encrypted_access_token',
        'encrypted_verify_token',
        'encrypted_app_secret',
        'verify_token_hash',
    ];

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

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'encrypted_access_token' => 'encrypted',
            'encrypted_verify_token' => 'encrypted',
            'encrypted_app_secret' => 'encrypted',
        ];
    }
}
