<?php

namespace App\Services\Customers;

use App\Enums\CustomerActivityType;
use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\CustomerTag;
use App\Models\Team;
use App\Models\User;
use App\Services\Tasks\TaskService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CustomerService
{
    public function __construct(
        private readonly CustomerIdentityResolutionService $identity,
        private readonly CustomerCustomFieldService $customFields,
        private readonly CustomerActivityService $activities,
        private readonly CustomerSummaryService $summary,
        private readonly CustomerSegmentService $segments,
        private readonly TaskService $tasks,
    ) {}

    /** @param array<string, mixed> $filters */
    public function index(Team $team, array $filters): array
    {
        $search = Str::limit(trim((string) ($filters['search'] ?? '')), 120, '');
        $status = CustomerStatus::tryFrom((string) ($filters['status'] ?? ''));
        $ownerId = is_numeric($filters['owner_id'] ?? null) ? (int) $filters['owner_id'] : null;
        $tagId = is_numeric($filters['tag'] ?? null) ? (int) $filters['tag'] : null;
        $segmentId = is_numeric($filters['segment'] ?? null) ? (int) $filters['segment'] : null;
        $query = $team->customers()->whereNull('merged_into_customer_id')->with('owner:id,name')->withCount(['conversations', 'leads', 'appointments', 'supportTickets', 'deals']);

        if ($search !== '') {
            $pattern = '%'.Str::lower($search).'%';
            $query->where(function (Builder $query) use ($pattern): void {
                $query->whereRaw("LOWER(COALESCE(display_name, '')) LIKE ?", [$pattern])
                    ->orWhereRaw("LOWER(COALESCE(first_name, '')) LIKE ?", [$pattern])
                    ->orWhereRaw("LOWER(COALESCE(last_name, '')) LIKE ?", [$pattern])
                    ->orWhereRaw("LOWER(COALESCE(email, '')) LIKE ?", [$pattern])
                    ->orWhereRaw("LOWER(COALESCE(phone, '')) LIKE ?", [$pattern])
                    ->orWhereRaw("LOWER(COALESCE(company, '')) LIKE ?", [$pattern]);
            });
        }
        if ($status instanceof CustomerStatus) {
            $query->where('status', $status->value);
        }
        if ($ownerId !== null) {
            $query->where('owner_id', $ownerId);
        }
        if ($tagId !== null) {
            $query->whereHas('tags', fn (Builder $tags): Builder => $tags->whereKey($tagId)->where('team_id', $team->id));
        }
        if ($segmentId !== null) {
            $segment = $team->customerSegments()->whereKey($segmentId)->firstOrFail();
            $query->whereIn('customers.id', $this->segments->query($team, $segment->filter_definition)->select('customers.id'));
        }

        return [
            'filters' => ['search' => $search !== '' ? $search : null, 'status' => $status?->value ?? 'all', 'ownerId' => $ownerId, 'tag' => $tagId, 'segment' => $segmentId],
            'customers' => $query->latest('last_activity_at')->latest('id')->paginate(25)->withQueryString()->through(fn (Customer $customer): array => $this->listItem($customer)),
            'statusOptions' => array_map(fn (CustomerStatus $item): array => ['key' => $item->value, 'label' => $item->label()], CustomerStatus::cases()),
            'ownerOptions' => $team->members()->select('users.id', 'users.name')->orderBy('name')->get()->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])->values()->all(),
            'tagOptions' => $team->customerTags()->select('id', 'name')->orderBy('name')->get()->map(fn (CustomerTag $tag): array => ['id' => $tag->id, 'name' => $tag->name])->values()->all(),
            'segmentOptions' => $team->customerSegments()->select('id', 'name')->orderBy('name')->get()->map(fn ($segment): array => ['id' => $segment->id, 'name' => $segment->name])->values()->all(),
        ];
    }

    /** @param array<string, mixed> $data */
    public function create(Team $team, array $data): Customer
    {
        $this->validateOwner($team, $data['owner_id'] ?? null);
        $identity = $this->identityData($data);
        $this->identity->ensureNoConflict($team, $identity);

        return DB::transaction(function () use ($team, $data, $identity): Customer {
            $customer = $team->customers()->create([
                ...$identity,
                'first_name' => $this->text($data['first_name'] ?? null, 100),
                'last_name' => $this->text($data['last_name'] ?? null, 100),
                'display_name' => Customer::buildName($data['first_name'] ?? null, $data['last_name'] ?? null),
                'status' => $data['status'],
                'owner_id' => $data['owner_id'] ?? null,
                'source' => 'manual',
                'last_activity_at' => now(),
            ]);
            $this->syncTags($team, $customer, $data['tags'] ?? []);
            $this->customFields->saveValues($team, $customer, is_array($data['custom_fields'] ?? null) ? $data['custom_fields'] : []);
            $this->activities->record($team, $customer, CustomerActivityType::Created, 'Customer created');

            return $customer;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Team $team, Customer $customer, array $data): Customer
    {
        return DB::transaction(function () use ($team, $customer, $data): Customer {
            $customer = $team->customers()->whereKey($customer->getKey())->firstOrFail();
            $this->validateOwner($team, $data['owner_id'] ?? null);
            $this->identity->ensureNoConflict($team, $this->identityData($data), $customer);
            $oldStatus = $customer->status->value;
            $oldOwner = $customer->owner_id;
            $customer->update([
                ...$this->identityData($data),
                'first_name' => $this->text($data['first_name'] ?? null, 100),
                'last_name' => $this->text($data['last_name'] ?? null, 100),
                'display_name' => Customer::buildName($data['first_name'] ?? null, $data['last_name'] ?? null),
                'status' => $data['status'],
                'owner_id' => $data['owner_id'] ?? null,
                'last_activity_at' => now(),
            ]);
            $this->syncTags($team, $customer, $data['tags'] ?? []);
            $this->customFields->saveValues($team, $customer, is_array($data['custom_fields'] ?? null) ? $data['custom_fields'] : []);

            if ($oldStatus !== $customer->status->value) {
                $this->activities->record($team, $customer, CustomerActivityType::StatusChanged, 'Status changed', $customer->status->label());
            }
            if ((int) $oldOwner !== (int) $customer->owner_id) {
                $this->activities->record($team, $customer, CustomerActivityType::OwnerChanged, 'Owner changed', $customer->owner?->name);
            }
            $this->activities->record($team, $customer, CustomerActivityType::Updated, 'Customer updated');

            return $customer->fresh(['owner', 'tags']) ?? $customer;
        });
    }

    public function addNote(Team $team, Customer $customer, User $user, string $body): void
    {
        $customer = $team->customers()->whereKey($customer->getKey())->firstOrFail();
        $customer->notes()->create(['team_id' => $team->id, 'user_id' => $user->id, 'body' => $body]);
        $customer->forceFill(['last_activity_at' => now()])->saveQuietly();
        $this->activities->record($team, $customer, CustomerActivityType::NoteAdded, 'Internal note added', Str::limit($body, 160, ''), $user);
    }

    public function syncTags(Team $team, Customer $customer, array $tagIds): void
    {
        $customer = $team->customers()->whereKey($customer->getKey())->firstOrFail();
        $tagIds = array_values(array_unique(array_map('intval', $tagIds)));
        $validTagIds = $team->customerTags()->whereKey($tagIds)->pluck('id')->all();

        if (count($validTagIds) !== count($tagIds)) {
            throw ValidationException::withMessages(['tags' => 'One or more selected tags do not belong to this Team.']);
        }

        $oldTagIds = $customer->tags()->pluck('customer_tags.id')->all();
        $customer->tags()->sync($validTagIds);

        if (array_values($oldTagIds) !== $validTagIds) {
            $this->activities->record($team, $customer, CustomerActivityType::TagChanged, 'Tags changed');
        }
    }

    public function createTag(Team $team, string $name): CustomerTag
    {
        return $team->customerTags()->firstOrCreate(['slug' => Str::slug($name)], ['name' => trim($name)]);
    }

    /** @return array<string, mixed> */
    public function detail(Team $team, Customer $customer): array
    {
        $customer = $team->customers()->whereKey($customer->getKey())->with([
            'owner:id,name', 'tags:id,name,slug', 'identities', 'facts', 'deals' => fn ($query) => $query->with(['pipeline:id,name', 'stage:id,name,semantic_type'])->latest('updated_at'), 'activities' => fn ($query) => $query->with('actor:id,name')->latest('occurred_at'), 'notes' => fn ($query) => $query->with('user:id,name')->latest(),
        ])->withCount(['conversations', 'leads', 'appointments', 'supportTickets', 'deals', 'deals as open_deals_count' => fn ($query) => $query->where('status', 'open'), 'deals as won_deals_count' => fn ($query) => $query->where('status', 'won'), 'deals as lost_deals_count' => fn ($query) => $query->where('status', 'lost')])->firstOrFail();

        return [
            'customer' => $this->profile($team, $customer),
            'statusOptions' => array_map(fn (CustomerStatus $status): array => ['key' => $status->value, 'label' => $status->label()], CustomerStatus::cases()),
            'ownerOptions' => $team->members()->select('users.id', 'users.name')->orderBy('name')->get()->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])->values()->all(),
            'tagOptions' => $team->customerTags()->select('id', 'name')->orderBy('name')->get()->map(fn (CustomerTag $tag): array => ['id' => $tag->id, 'name' => $tag->name])->values()->all(),
            'customFields' => collect($this->customFields->fields($team))->map(fn ($field): array => ['id' => $field->id, 'key' => $field->key, 'label' => $field->label, 'type' => $field->type, 'required' => $field->required, 'active' => $field->active, 'sortOrder' => $field->sort_order, 'options' => $field->options ?? []])->values()->all(),
            'segmentOptions' => $team->customerSegments()->select('id', 'name')->orderBy('name')->get()->map(fn ($segment): array => ['id' => $segment->id, 'name' => $segment->name])->values()->all(),
            'tasks' => $this->tasks->forCustomer($team, $customer),
        ];
    }

    /** @return array<string, mixed> */
    private function profile(Team $team, Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'firstName' => $customer->first_name,
            'lastName' => $customer->last_name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'company' => $customer->company,
            'status' => $customer->status->value,
            'statusLabel' => $customer->status->label(),
            'source' => $customer->source,
            'owner' => $customer->owner?->only(['id', 'name']),
            'tags' => $customer->tags->map(fn (CustomerTag $tag): array => ['id' => $tag->id, 'name' => $tag->name])->values()->all(),
            'identities' => $customer->identities->map(fn ($identity): array => ['id' => $identity->id, 'type' => $identity->type, 'value' => $identity->value, 'provider' => $identity->provider, 'providerExternalId' => $identity->provider_external_id, 'isPrimary' => $identity->is_primary, 'isVerified' => $identity->is_verified])->values()->all(),
            'customFields' => array_values($this->customFields->displayValues($team, $customer)),
            'facts' => $customer->facts->map(fn ($fact): array => ['id' => $fact->id, 'key' => $fact->key, 'value' => $fact->value, 'valueType' => $fact->value_type, 'source' => $fact->source, 'lastConfirmedAt' => $fact->last_confirmed_at?->toAtomString()])->values()->all(),
            'aiSummary' => $customer->ai_summary,
            'aiSummaryGeneratedAt' => $customer->ai_summary_generated_at?->toAtomString(),
            'summaryStale' => $this->summary->isStale($customer),
            'firstSeenAt' => $customer->created_at?->toAtomString(),
            'lastActivityAt' => $customer->last_activity_at?->toAtomString(),
            'counts' => ['conversations' => $customer->conversations_count, 'leads' => $customer->leads_count, 'appointments' => $customer->appointments_count, 'supportTickets' => $customer->support_tickets_count, 'deals' => $customer->deals_count, 'openDeals' => $customer->open_deals_count, 'wonDeals' => $customer->won_deals_count, 'lostDeals' => $customer->lost_deals_count, 'openTickets' => $customer->supportTickets()->whereIn('status', ['open', 'in_progress'])->count(), 'upcomingAppointments' => $customer->appointments()->where('starts_at', '>=', now())->where('status', 'scheduled')->count()],
            'deals' => $customer->deals->map(fn ($deal): array => ['id' => $deal->id, 'title' => $deal->title, 'status' => $deal->status->value, 'valueAmount' => $deal->value_amount === null ? null : (string) $deal->value_amount, 'currency' => $deal->currency, 'pipeline' => ['id' => $deal->pipeline->id, 'name' => $deal->pipeline->name], 'stage' => ['id' => $deal->stage->id, 'name' => $deal->stage->name]])->values()->all(),
            'notes' => $customer->notes->map(fn ($note): array => ['id' => $note->id, 'body' => $note->body, 'author' => $note->user?->name, 'createdAt' => $note->created_at?->toAtomString()])->values()->all(),
            'timeline' => $this->timeline($team, $customer),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function timeline(Team $team, Customer $customer): array
    {
        $events = $customer->activities->map(fn ($activity): array => ['type' => $activity->type, 'title' => $activity->title, 'description' => $activity->description, 'timestamp' => $activity->occurred_at?->toAtomString(), 'actor' => $activity->actor?->name, 'url' => $activity->related_url])->collect();
        $customer->conversations()->select('id', 'public_id', 'created_at')->latest()->limit(25)->get()->each(fn ($record) => $events->push(['type' => 'conversation', 'title' => 'Conversation started', 'timestamp' => $record->created_at?->toAtomString(), 'url' => route('conversations.show', [$team->slug, $record->public_id])]));
        $customer->leads()->select('id', 'public_id', 'name', 'created_at')->latest()->limit(25)->get()->each(fn ($record) => $events->push(['type' => 'lead', 'title' => 'Lead created', 'description' => $record->name, 'timestamp' => $record->created_at?->toAtomString(), 'url' => route('leads.show', [$team->slug, $record->public_id])]));
        $customer->appointments()->select('id', 'public_id', 'created_at')->latest()->limit(25)->get()->each(fn ($record) => $events->push(['type' => 'appointment', 'title' => 'Appointment created', 'timestamp' => $record->created_at?->toAtomString(), 'url' => route('appointments.show', [$team->slug, $record->public_id])]));
        $customer->supportTickets()->select('id', 'public_id', 'subject', 'created_at')->latest()->limit(25)->get()->each(fn ($record) => $events->push(['type' => 'ticket', 'title' => 'Support ticket created', 'description' => $record->subject, 'timestamp' => $record->created_at?->toAtomString(), 'url' => route('support-tickets.show', [$team->slug, $record->public_id])]));
        $customer->notes()->select('id', 'user_id', 'created_at')->with('user:id,name')->latest()->limit(25)->get()->each(fn ($record) => $events->push(['type' => 'note', 'title' => 'Internal note added', 'description' => $record->user?->name, 'timestamp' => $record->created_at?->toAtomString()]));
        $customer->deals()->select('id', 'title', 'created_at')->latest()->limit(25)->get()->each(fn ($record) => $events->push(['type' => 'deal', 'title' => 'Deal created', 'description' => $record->title, 'timestamp' => $record->created_at?->toAtomString(), 'url' => route('deals.show', [$team->slug, $record->id])]));

        return $events->sortByDesc('timestamp')->values()->all();
    }

    /** @return array{email: string|null, phone: string|null} */
    private function identityData(array $data): array
    {
        return ['email' => $this->text($data['email'] ?? null, 320), 'phone' => $this->text($data['phone'] ?? null, 64)];
    }

    private function validateOwner(Team $team, mixed $ownerId): void
    {
        if ($ownerId !== null && ! $team->members()->whereKey((int) $ownerId)->exists()) {
            throw ValidationException::withMessages(['owner_id' => 'The selected owner must belong to this Team.']);
        }
    }

    private function text(mixed $value, int $limit): ?string
    {
        return is_string($value) && trim($value) !== '' ? Str::limit(trim($value), $limit, '') : null;
    }

    /** @return array<string, mixed> */
    private function listItem(Customer $customer): array
    {
        return ['id' => $customer->id, 'name' => $customer->name, 'email' => $customer->email, 'phone' => $customer->phone, 'company' => $customer->company, 'status' => $customer->status->value, 'statusLabel' => $customer->status->label(), 'owner' => $customer->owner?->only(['id', 'name']), 'lastActivityAt' => $customer->last_activity_at?->toAtomString(), 'updatedAt' => $customer->updated_at?->toAtomString(), 'counts' => ['conversations' => $customer->conversations_count, 'leads' => $customer->leads_count, 'appointments' => $customer->appointments_count, 'supportTickets' => $customer->support_tickets_count]];
    }
}
