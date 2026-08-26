<?php

namespace App\Models;

use Database\Factories\DatasetFieldFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DatasetField extends Model
{
    /** @use HasFactory<DatasetFieldFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * Get the dataset this field belongs to.
     *
     * @return BelongsTo<Dataset, $this>
     */
    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'allowed_operators' => 'array',
            'config' => 'array',
            'is_searchable' => 'boolean',
            'is_filterable' => 'boolean',
            'is_sortable' => 'boolean',
            'is_semantic' => 'boolean',
            'is_displayable' => 'boolean',
            'position' => 'integer',
        ];
    }
}
