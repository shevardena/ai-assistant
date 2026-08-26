<?php

namespace App\Services\Tasks;

use App\Enums\CustomerActivityType;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\SupportTicket;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Services\Customers\CustomerActivityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class TaskService
{
    public function __construct(private readonly CustomerActivityService $activities) {}

    /** @param array<string, mixed> $filters */
    public function index(Team $team, array $filters, User $actor): array
    {
        $query = $this->filteredQuery($team, $filters, $actor);
        $tasks = (clone $query)
            ->with($this->listRelations())
            ->orderByRaw("CASE WHEN status IN ('open', 'in_progress') AND due_at IS NOT NULL AND due_at < ? THEN 0 WHEN due_at IS NOT NULL THEN 1 ELSE 2 END", [now()])
            ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_at')
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Task $task): array => $this->listItem($task));

        return [
            'filters' => $this->filterPayload($filters, $actor),
            'tasks' => $tasks,
            'metrics' => $this->metrics($team, $actor),
            'assigneeOptions' => $this->assigneeOptions($team),
            'customerOptions' => $this->customerOptions($team),
            'leadOptions' => $this->leadOptions($team),
            'dealOptions' => $this->dealOptions($team),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(Team $team, Task $task): array
    {
        $task = $team->tasks()->whereKey($task->getKey())->with($this->detailRelations())->firstOrFail();

        return [
            'task' => $this->detailItem($task),
            'activity' => $this->activityPayload($task),
            ...$this->formOptions($team),
        ];
    }

    /** @return array<string, mixed> */
    public function formOptions(Team $team): array
    {
        return [
            'assigneeOptions' => $this->assigneeOptions($team),
            'customerOptions' => $this->customerOptions($team),
            'leadOptions' => $this->leadOptions($team),
            'dealOptions' => $this->dealOptions($team),
            'conversationOptions' => $team->conversations()->select('conversations.id', 'conversations.public_id')->latest('conversations.id')->limit(100)->get()->map(fn (Conversation $conversation): array => ['id' => $conversation->id, 'reference' => $conversation->public_id])->values()->all(),
            'ticketOptions' => $team->supportTickets()->select('id', 'public_id', 'subject')->latest('id')->limit(100)->get()->map(fn (SupportTicket $ticket): array => ['id' => $ticket->id, 'reference' => $ticket->public_id, 'title' => $ticket->subject])->values()->all(),
            'appointmentOptions' => $team->appointments()->select('id', 'public_id', 'starts_at')->latest('id')->limit(100)->get()->map(fn (Appointment $appointment): array => ['id' => $appointment->id, 'reference' => $appointment->public_id, 'startsAt' => $appointment->starts_at?->toAtomString()])->values()->all(),
            'statusOptions' => array_map(fn (TaskStatus $status): array => ['key' => $status->value, 'label' => Str::headline($status->value)], [TaskStatus::Open, TaskStatus::InProgress]),
            'priorityOptions' => array_map(fn (TaskPriority $priority): array => ['key' => $priority->value, 'label' => Str::headline($priority->value)], TaskPriority::cases()),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function forCustomer(Team $team, Customer $customer): array
    {
        $customer = $team->customers()->whereKey($customer->getKey())->firstOrFail();

        return $team->tasks()->where('customer_id', $customer->getKey())->with($this->listRelations())->latest('updated_at')->limit(50)->get()->map(fn (Task $task): array => $this->listItem($task))->values()->all();
    }

    /** @return list<array<string, mixed>> */
    public function forLead(Team $team, Lead $lead): array
    {
        $lead = $team->leads()->whereKey($lead->getKey())->firstOrFail();

        return $team->tasks()->where('lead_id', $lead->getKey())->with($this->listRelations())->latest('updated_at')->limit(50)->get()->map(fn (Task $task): array => $this->listItem($task))->values()->all();
    }

    /** @return list<array<string, mixed>> */
    public function forDeal(Team $team, Deal $deal): array
    {
        $deal = $team->deals()->whereKey($deal->getKey())->firstOrFail();

        return $team->tasks()->where('deal_id', $deal->getKey())->with($this->listRelations())->latest('updated_at')->limit(50)->get()->map(fn (Task $task): array => $this->listItem($task))->values()->all();
    }

    /** @param array<string, mixed> $data */
    public function create(Team $team, array $data, ?User $actor = null, string $source = 'manual'): Task
    {
        return DB::transaction(function () use ($team, $data, $actor, $source): Task {
            $relations = $this->validatedRelations($team, $data);
            $customerId = $this->consistentCustomerId($relations, $data['customer_id'] ?? null);
            $assignedUserId = $this->assignedUserId($team, $data['assigned_user_id'] ?? null);
            $status = TaskStatus::tryFrom((string) ($data['status'] ?? TaskStatus::Open->value));
            if (! $status || in_array($status, [TaskStatus::Completed, TaskStatus::Cancelled], true)) {
                throw ValidationException::withMessages(['status' => 'New Tasks must start open or in progress.']);
            }
            $source = in_array($source, ['manual', 'workflow', 'system'], true) ? $source : 'manual';

            $task = $team->tasks()->create([
                'title' => trim((string) $data['title']),
                'description' => $this->nullableText($data['description'] ?? null, 5000),
                'status' => $status,
                'priority' => TaskPriority::from((string) ($data['priority'] ?? TaskPriority::Normal->value)),
                'assigned_user_id' => $assignedUserId,
                'created_by_user_id' => $actor?->getKey(),
                'due_at' => $data['due_at'] ?? null,
                'customer_id' => $customerId,
                'lead_id' => $relations['lead']?->getKey(),
                'deal_id' => $relations['deal']?->getKey(),
                'conversation_id' => $relations['conversation']?->getKey(),
                'support_ticket_id' => $relations['ticket']?->getKey(),
                'appointment_id' => $relations['appointment']?->getKey(),
                'source' => $source,
                'last_activity_at' => now(),
            ]);
            $this->record($team, $task, CustomerActivityType::TaskCreated, 'Task created', $actor);

            return $task->load($this->detailRelations());
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Team $team, Task $task, array $data, ?User $actor = null): Task
    {
        return DB::transaction(function () use ($team, $task, $data, $actor): Task {
            $task = $team->tasks()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();
            $relations = $this->validatedRelations($team, $data);
            $old = ['assigned_user_id' => $task->assigned_user_id, 'due_at' => $task->due_at?->toAtomString(), 'priority' => $task->priority->value, 'customer_id' => $task->customer_id];
            $customerId = $this->consistentCustomerId($relations, $data['customer_id'] ?? null);
            $task->update([
                'title' => trim((string) $data['title']),
                'description' => $this->nullableText($data['description'] ?? null, 5000),
                'priority' => TaskPriority::from((string) $data['priority']),
                'assigned_user_id' => $this->assignedUserId($team, $data['assigned_user_id'] ?? null),
                'due_at' => $data['due_at'] ?? null,
                'customer_id' => $customerId,
                'lead_id' => $relations['lead']?->getKey(),
                'deal_id' => $relations['deal']?->getKey(),
                'conversation_id' => $relations['conversation']?->getKey(),
                'support_ticket_id' => $relations['ticket']?->getKey(),
                'appointment_id' => $relations['appointment']?->getKey(),
                'last_activity_at' => now(),
            ]);
            $task = $task->fresh($this->detailRelations()) ?? $task;
            if ((int) $old['assigned_user_id'] !== (int) $task->assigned_user_id) {
                $this->record($team, $task, CustomerActivityType::TaskAssigneeChanged, 'Task assignee changed', $actor, $task->assignee?->name);
            }
            if ((string) $old['due_at'] !== (string) $task->due_at?->toAtomString()) {
                $this->record($team, $task, CustomerActivityType::TaskDueChanged, 'Task due date changed', $actor);
            }
            if ($old['priority'] !== $task->priority->value) {
                $this->record($team, $task, CustomerActivityType::TaskPriorityChanged, 'Task priority changed', $actor, $task->priority->value);
            }

            return $task;
        });
    }

    public function complete(Team $team, Task $task, ?User $actor = null): Task
    {
        return $this->transition($team, $task, TaskStatus::Completed, $actor);
    }

    public function reopen(Team $team, Task $task, ?User $actor = null): Task
    {
        return $this->transition($team, $task, TaskStatus::Open, $actor);
    }

    public function cancel(Team $team, Task $task, ?User $actor = null): Task
    {
        return $this->transition($team, $task, TaskStatus::Cancelled, $actor);
    }

    private function transition(Team $team, Task $task, TaskStatus $status, ?User $actor): Task
    {
        return DB::transaction(function () use ($team, $task, $status, $actor): Task {
            $task = $team->tasks()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();
            if ($status === TaskStatus::Completed && $task->status === TaskStatus::Cancelled) {
                throw ValidationException::withMessages(['task' => 'Cancelled Tasks cannot be completed.']);
            }
            if ($status === TaskStatus::Open && $task->status !== TaskStatus::Completed) {
                throw ValidationException::withMessages(['task' => 'Only completed Tasks can be reopened.']);
            }
            if ($status === TaskStatus::Cancelled && $task->status === TaskStatus::Completed) {
                throw ValidationException::withMessages(['task' => 'Completed Tasks cannot be cancelled.']);
            }
            if ($task->status === $status) {
                return $task->fresh($this->detailRelations()) ?? $task;
            }
            $task->forceFill(['status' => $status, 'completed_at' => $status === TaskStatus::Completed ? now() : null, 'last_activity_at' => now()])->save();
            $type = match ($status) {
                TaskStatus::Completed => CustomerActivityType::TaskCompleted,
                TaskStatus::Cancelled => CustomerActivityType::TaskCancelled,
                default => CustomerActivityType::TaskReopened,
            };
            $title = match ($status) {
                TaskStatus::Completed => 'Task completed',
                TaskStatus::Cancelled => 'Task cancelled',
                default => 'Task reopened',
            };
            $this->record($team, $task, $type, $title, $actor);

            return $task->fresh($this->detailRelations()) ?? $task;
        });
    }

    /** @return array{customer: Customer|null, lead: Lead|null, deal: Deal|null, conversation: Conversation|null, ticket: SupportTicket|null, appointment: Appointment|null} */
    private function validatedRelations(Team $team, array $data): array
    {
        $customer = $this->optional($team->customers(), $data['customer_id'] ?? null);
        $lead = $this->optional($team->leads(), $data['lead_id'] ?? null);
        $deal = $this->optional($team->deals()->with(['customer', 'lead']), $data['deal_id'] ?? null);
        $conversation = $this->optional($team->conversations(), $data['conversation_id'] ?? null);
        $ticket = $this->optional($team->supportTickets(), $data['support_ticket_id'] ?? null);
        $appointment = $this->optional($team->appointments(), $data['appointment_id'] ?? null);

        if ($deal?->lead_id !== null && $lead !== null && $deal->lead_id !== $lead->getKey()) {
            throw ValidationException::withMessages(['lead_id' => 'The selected Lead does not belong to the selected Deal.']);
        }
        if ($deal !== null && $deal->lead_id !== null) {
            $lead ??= $deal->lead;
        }

        return compact('customer', 'lead', 'deal', 'conversation', 'ticket', 'appointment');
    }

    private function consistentCustomerId(array $relations, mixed $submittedCustomerId): ?int
    {
        $customer = $relations['customer'];
        $dealCustomer = $relations['deal']?->customer;
        $leadCustomer = $relations['lead']?->customer;
        $strongest = $dealCustomer ?? $leadCustomer;
        if ($strongest instanceof Customer) {
            return $strongest->getKey();
        }

        return $customer?->getKey();
    }

    private function assignedUserId(Team $team, mixed $assignedUserId): ?int
    {
        if ($assignedUserId === null || $assignedUserId === '') {
            return null;
        }
        $assignedUserId = (int) $assignedUserId;
        if (! $team->members()->whereKey($assignedUserId)->exists()) {
            throw ValidationException::withMessages(['assigned_user_id' => 'The selected assignee must belong to this Team.']);
        }

        return $assignedUserId;
    }

    private function optional(mixed $query, mixed $id): mixed
    {
        if ($id === null || $id === '') {
            return null;
        }

        return $query->whereKey((int) $id)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function metrics(Team $team, User $actor): array
    {
        $base = $team->tasks()->whereIn('status', [TaskStatus::Open->value, TaskStatus::InProgress->value]);

        return [
            'open' => (clone $base)->count(),
            'mine' => (clone $base)->where('assigned_user_id', $actor->getKey())->count(),
            'overdue' => (clone $base)->whereNotNull('due_at')->where('due_at', '<', now())->count(),
            'upcoming' => (clone $base)->whereBetween('due_at', [now(), now()->addDays(7)])->count(),
            'completed' => $team->tasks()->where('status', TaskStatus::Completed->value)->count(),
        ];
    }

    /** @param array<string, mixed> $filters */
    private function filteredQuery(Team $team, array $filters, User $actor): Builder
    {
        $query = $team->tasks()->getQuery();
        $scope = (string) ($filters['scope'] ?? 'my');
        if ($scope === 'my') {
            $query->where('assigned_user_id', $actor->getKey());
        }
        if ($scope === 'overdue') {
            $query->whereIn('status', [TaskStatus::Open->value, TaskStatus::InProgress->value])->whereNotNull('due_at')->where('due_at', '<', now());
        } elseif ($scope === 'upcoming') {
            $query->whereIn('status', [TaskStatus::Open->value, TaskStatus::InProgress->value])->whereBetween('due_at', [now(), now()->addDays(7)]);
        } elseif ($scope === 'completed') {
            $query->where('status', TaskStatus::Completed->value);
        }
        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        } elseif ($scope !== 'completed' && $scope !== 'overdue' && $scope !== 'upcoming') {
            $query->whereIn('status', [TaskStatus::Open->value, TaskStatus::InProgress->value]);
        }
        foreach (['priority', 'assigned_user_id', 'customer_id', 'lead_id', 'deal_id'] as $field) {
            if (($filters[$field] ?? null) !== null && $filters[$field] !== '') {
                $query->where($field, (int) $filters[$field]);
            }
        }
        if (! empty($filters['due_from'])) {
            $query->where('due_at', '>=', Carbon::parse((string) $filters['due_from'])->startOfDay());
        }
        if (! empty($filters['due_to'])) {
            $query->where('due_at', '<=', Carbon::parse((string) $filters['due_to'])->endOfDay());
        }
        if (! empty($filters['search'])) {
            $search = '%'.Str::lower(Str::limit((string) $filters['search'], 120, '')).'%';
            $query->where(function (Builder $builder) use ($search): void {
                $builder->whereRaw('LOWER(title) LIKE ?', [$search])
                    ->orWhereRaw("LOWER(COALESCE(description, '')) LIKE ?", [$search])
                    ->orWhereHas('customer', fn (Builder $customer) => $customer->whereRaw("LOWER(COALESCE(display_name, '')) LIKE ?", [$search]))
                    ->orWhereHas('deal', fn (Builder $deal) => $deal->whereRaw('LOWER(title) LIKE ?', [$search]))
                    ->orWhereHas('lead', fn (Builder $lead) => $lead->whereRaw("LOWER(COALESCE(name, '')) LIKE ?", [$search]));
            });
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function filterPayload(array $filters, User $actor): array
    {
        return ['scope' => $filters['scope'] ?? 'my', 'status' => $filters['status'] ?? null, 'priority' => $filters['priority'] ?? null, 'assignedUserId' => isset($filters['assigned_user_id']) ? (int) $filters['assigned_user_id'] : null, 'customerId' => isset($filters['customer_id']) ? (int) $filters['customer_id'] : null, 'leadId' => isset($filters['lead_id']) ? (int) $filters['lead_id'] : null, 'dealId' => isset($filters['deal_id']) ? (int) $filters['deal_id'] : null, 'dueFrom' => $filters['due_from'] ?? null, 'dueTo' => $filters['due_to'] ?? null, 'search' => $filters['search'] ?? null, 'actorId' => $actor->getKey()];
    }

    /** @return array<string, mixed> */
    private function listItem(Task $task): array
    {
        return [...$this->taskPayload($task), 'overdue' => $this->isOverdue($task), 'relatedTo' => $this->relatedTo($task)];
    }

    /** @return array<string, mixed> */
    private function detailItem(Task $task): array
    {
        return [...$this->listItem($task), 'activity' => $this->activityPayload($task)];
    }

    /** @return array<string, mixed> */
    private function taskPayload(Task $task): array
    {
        return ['id' => $task->id, 'title' => $task->title, 'description' => $task->description, 'status' => $task->status->value, 'priority' => $task->priority->value, 'assignee' => $task->assignee?->only(['id', 'name']), 'creator' => $task->creator?->only(['id', 'name']), 'dueAt' => $task->due_at?->toAtomString(), 'completedAt' => $task->completed_at?->toAtomString(), 'source' => $task->source, 'customer' => $task->customer ? ['id' => $task->customer->id, 'name' => $task->customer->name] : null, 'lead' => $task->lead ? ['id' => $task->lead->id, 'reference' => $task->lead->public_id, 'name' => $task->lead->name] : null, 'deal' => $task->deal ? ['id' => $task->deal->id, 'title' => $task->deal->title] : null, 'conversation' => $task->conversation ? ['id' => $task->conversation->id, 'reference' => $task->conversation->public_id] : null, 'ticket' => $task->supportTicket ? ['id' => $task->supportTicket->id, 'reference' => $task->supportTicket->public_id, 'title' => $task->supportTicket->subject] : null, 'appointment' => $task->appointment ? ['id' => $task->appointment->id, 'reference' => $task->appointment->public_id, 'startsAt' => $task->appointment->starts_at?->toAtomString()] : null, 'updatedAt' => $task->updated_at?->toAtomString()];
    }

    private function relatedTo(Task $task): ?array
    {
        if ($task->deal) {
            return ['type' => 'deal', 'label' => 'Deal: '.$task->deal->title, 'id' => $task->deal->id];
        }
        if ($task->customer) {
            return ['type' => 'customer', 'label' => 'Customer: '.$task->customer->name, 'id' => $task->customer->id];
        }
        if ($task->lead) {
            return ['type' => 'lead', 'label' => 'Lead: '.($task->lead->name ?: $task->lead->public_id), 'id' => $task->lead->id];
        }
        if ($task->supportTicket) {
            return ['type' => 'ticket', 'label' => 'Ticket: '.($task->supportTicket->subject ?: $task->supportTicket->public_id), 'id' => $task->supportTicket->id];
        }
        if ($task->appointment) {
            return ['type' => 'appointment', 'label' => 'Appointment: '.$task->appointment->public_id, 'id' => $task->appointment->id];
        }
        if ($task->conversation) {
            return ['type' => 'conversation', 'label' => 'Conversation: '.$task->conversation->public_id, 'id' => $task->conversation->id];
        }

        return null;
    }

    private function isOverdue(Task $task): bool
    {
        return in_array($task->status, [TaskStatus::Open, TaskStatus::InProgress], true) && $task->due_at?->isBefore(now()) === true;
    }

    /** @return list<array<string, mixed>> */
    private function activityPayload(Task $task): array
    {
        return $task->customer?->activities()->where('related_type', $task->getMorphClass())->where('related_id', $task->id)->with('actor:id,name')->latest('occurred_at')->get()->map(fn ($activity): array => ['type' => $activity->type, 'title' => $activity->title, 'description' => $activity->description, 'actor' => $activity->actor?->name, 'timestamp' => $activity->occurred_at?->toAtomString()])->values()->all() ?? [];
    }

    private function record(Team $team, Task $task, CustomerActivityType $type, string $title, ?User $actor, ?string $description = null): void
    {
        if ($task->customer_id === null) {
            return;
        }
        $customer = $team->customers()->whereKey($task->customer_id)->firstOrFail();
        $this->activities->record($team, $customer, $type, $title, $description, $actor, $task, route('tasks.show', [$team->slug, $task->id]));
    }

    /** @return array<string, mixed> */
    private function assigneeOptions(Team $team): array
    {
        return $team->members()->select('users.id', 'users.name')->orderBy('name')->get()->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])->values()->all();
    }

    /** @return array<string, mixed> */
    private function customerOptions(Team $team): array
    {
        return $team->customers()->whereNull('merged_into_customer_id')->select('id', 'display_name', 'email')->orderBy('display_name')->limit(200)->get()->map(fn (Customer $customer): array => ['id' => $customer->id, 'name' => $customer->name, 'email' => $customer->email])->values()->all();
    }

    /** @return array<string, mixed> */
    private function leadOptions(Team $team): array
    {
        return $team->leads()->select('id', 'public_id', 'name', 'email')->latest('id')->limit(200)->get()->map(fn (Lead $lead): array => ['id' => $lead->id, 'reference' => $lead->public_id, 'name' => $lead->name ?? 'Lead', 'email' => $lead->email])->values()->all();
    }

    /** @return array<string, mixed> */
    private function dealOptions(Team $team): array
    {
        return $team->deals()->select('id', 'title', 'customer_id')->latest('id')->limit(200)->get()->map(fn (Deal $deal): array => ['id' => $deal->id, 'title' => $deal->title, 'customerId' => $deal->customer_id])->values()->all();
    }

    /** @return array<string, mixed> */
    private function listRelations(): array
    {
        return ['assignee:id,name', 'creator:id,name', 'customer:id,display_name,email', 'lead:id,public_id,name', 'deal:id,title', 'conversation:id,public_id', 'supportTicket:id,public_id,subject', 'appointment:id,public_id,starts_at'];
    }

    /** @return array<string, mixed> */
    private function detailRelations(): array
    {
        return [...$this->listRelations(), 'deal:id,title,customer_id,lead_id', 'deal.customer:id,display_name', 'lead:id,public_id,name,customer_id', 'lead.customer:id,display_name'];
    }

    private function nullableText(mixed $value, int $limit): ?string
    {
        return is_string($value) && trim($value) !== '' ? Str::limit(trim($value), $limit, '') : null;
    }
}
