<?php

namespace App\Models;

use Database\Factories\BillingAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $user_id
 * @property Carbon|null $free_workspace_consumed_at
 * @property-read User $user
 */
#[Fillable(['user_id'])]
class BillingAccount extends Model
{
    /** @use HasFactory<BillingAccountFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'free_workspace_consumed_at' => 'datetime',
        ];
    }
}
