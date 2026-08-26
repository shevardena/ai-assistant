<?php

namespace App\Models;

use App\Enums\WorkspaceProvisioningStatus;
use Database\Factories\WorkspaceProvisioningFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceProvisioning extends Model
{
    /** @use HasFactory<WorkspaceProvisioningFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => WorkspaceProvisioningStatus::class,
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
