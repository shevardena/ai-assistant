<?php

namespace App\Models;

use Database\Factories\CustomerCustomFieldValueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerCustomFieldValue extends Model
{
    /** @use HasFactory<CustomerCustomFieldValueFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['team_id', 'customer_id', 'customer_custom_field_id', 'value_text', 'value_number', 'value_boolean', 'value_date', 'value_datetime', 'value_json'];

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<CustomerCustomField, $this> */
    public function field(): BelongsTo
    {
        return $this->belongsTo(CustomerCustomField::class, 'customer_custom_field_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['value_number' => 'decimal:6', 'value_boolean' => 'boolean', 'value_date' => 'date:Y-m-d', 'value_datetime' => 'datetime', 'value_json' => 'array'];
    }
}
