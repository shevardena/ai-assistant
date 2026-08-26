<?php

namespace App\Models;

use App\Enums\CustomerStatus;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** @property-read Collection<int, CustomerTag> $tags */
#[Fillable([
    'team_id', 'owner_id', 'first_name', 'last_name', 'display_name', 'email',
    'normalized_email', 'phone', 'normalized_phone', 'company', 'status',
    'source', 'last_activity_at', 'merged_into_customer_id', 'merged_at',
    'ai_summary', 'ai_summary_generated_at', 'ai_summary_activity_at',
])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    protected $attributes = ['status' => CustomerStatus::New->value];

    protected static function booted(): void
    {
        static::saving(function (Customer $customer): void {
            $customer->email = self::clean($customer->email);
            $customer->phone = self::clean($customer->phone);
            $customer->normalized_email = self::normalizeEmail($customer->email);
            $customer->normalized_phone = self::normalizePhone($customer->phone);
            $customer->display_name = self::clean($customer->display_name)
                ?? self::buildName($customer->first_name, $customer->last_name);
        });

        static::saved(function (Customer $customer): void {
            if (! Schema::hasTable('customer_identities')) {
                return;
            }

            foreach ([['field' => 'email', 'type' => 'email'], ['field' => 'phone', 'type' => 'phone']] as $identity) {
                $value = $customer->getAttribute($identity['field']);
                $normalized = $identity['type'] === 'email' ? self::normalizeEmail($value) : self::normalizePhone($value);
                $original = $customer->getOriginal($identity['field']);
                $originalNormalized = $identity['type'] === 'email' ? self::normalizeEmail($original) : self::normalizePhone($original);

                if ($originalNormalized !== null && $originalNormalized !== $normalized) {
                    $customer->identities()->where('type', $identity['type'])->where('normalized_value', $originalNormalized)->update(['is_primary' => false]);
                }

                if ($normalized !== null) {
                    CustomerIdentity::query()->updateOrCreate(
                        ['team_id' => $customer->team_id, 'type' => $identity['type'], 'normalized_value' => $normalized],
                        ['customer_id' => $customer->id, 'value' => $value, 'is_primary' => true],
                    );
                }
            }
        });
    }

    public static function normalizeEmail(?string $email): ?string
    {
        $email = self::clean($email);

        return $email === null ? null : Str::lower($email);
    }

    public static function normalizePhone(?string $phone): ?string
    {
        $phone = self::clean($phone);
        $digits = $phone === null ? '' : (preg_replace('/\D+/', '', $phone) ?: '');

        return $digits === '' ? null : $digits;
    }

    public static function buildName(?string $firstName, ?string $lastName): ?string
    {
        $name = trim(implode(' ', array_filter([$firstName, $lastName])));

        return $name === '' ? null : $name;
    }

    public function getNameAttribute(): string
    {
        return $this->display_name
            ?? self::buildName($this->first_name, $this->last_name)
            ?? $this->email
            ?? $this->phone
            ?? 'Unnamed customer';
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<Customer, $this> */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_customer_id');
    }

    /** @return HasMany<CustomerIdentity, $this> */
    public function identities(): HasMany
    {
        return $this->hasMany(CustomerIdentity::class);
    }

    /** @return HasMany<CustomerCustomFieldValue, $this> */
    public function customFieldValues(): HasMany
    {
        return $this->hasMany(CustomerCustomFieldValue::class);
    }

    /** @return HasMany<CustomerFact, $this> */
    public function facts(): HasMany
    {
        return $this->hasMany(CustomerFact::class);
    }

    /** @return HasMany<CustomerActivity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(CustomerActivity::class);
    }

    /** @return HasMany<Conversation, $this> */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /** @return HasMany<Lead, $this> */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /** @return HasMany<Deal, $this> */
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /** @return HasMany<Appointment, $this> */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /** @return HasMany<SupportTicket, $this> */
    public function supportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class);
    }

    /** @return HasMany<CustomerNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(CustomerNote::class);
    }

    /** @return BelongsToMany<CustomerTag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(CustomerTag::class, 'customer_customer_tag');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => CustomerStatus::class,
            'last_activity_at' => 'datetime',
            'merged_at' => 'datetime',
            'ai_summary_generated_at' => 'datetime',
            'ai_summary_activity_at' => 'datetime',
        ];
    }

    private static function clean(?string $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
