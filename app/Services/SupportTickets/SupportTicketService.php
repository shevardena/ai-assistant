<?php

namespace App\Services\SupportTickets;

use App\Enums\SupportTicketStatus;
use App\Models\Bot;
use App\Models\SupportTicket;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class SupportTicketService
{
    private const PER_PAGE = 25;

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function index(Team $team, array $filters): array
    {
        $bots = $team->bots()->select(['id', 'name', 'slug'])->orderBy('name')->get();
        $botFilter = $this->string($filters['bot'] ?? null);
        $selectedBot = $botFilter === null ? null : $bots->first(fn (Bot $bot): bool => $bot->slug === $botFilter);
        $range = $this->range($filters['range'] ?? '30d');
        $status = $this->status($filters['status'] ?? 'all');
        $search = Str::limit(trim((string) ($filters['search'] ?? '')), 120, '');
        $base = $this->base($team, $selectedBot, $botFilter, $range, $search);
        $query = clone $base;

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $tickets = $query->select(['id', 'public_id', 'bot_id', 'conversation_id', 'customer_id', 'status', 'subject', 'summary', 'customer_name', 'customer_email', 'provider_reference', 'external_url', 'created_at'])->with(['bot:id,name,slug', 'customer:id,display_name,email,phone'])->latest('created_at')->latest('id')->paginate(self::PER_PAGE)->withQueryString();

        return ['filters' => ['bot' => $selectedBot?->slug, 'range' => $range['key'], 'status' => $status, 'search' => $search !== '' ? $search : null], 'botOptions' => $bots->map(fn (Bot $bot): array => ['id' => $bot->id, 'name' => $bot->name, 'slug' => $bot->slug])->values()->all(), 'statusOptions' => $this->statusOptions(), 'summary' => $this->summary($base), 'tickets' => $tickets->through(fn (SupportTicket $ticket): array => $this->listItem($ticket))];
    }

    /** @return array{ticket: array<string, mixed>, statusOptions: list<array{key: string, label: string}>} */
    public function detail(Team $team, SupportTicket $ticket): array
    {
        $scoped = $team->supportTickets()->whereKey($ticket->getKey())->with(['bot:id,name,slug', 'conversation:id,public_id', 'customer:id,display_name,email,phone', 'toolRun:id,action_reference,status,completed_at'])->firstOrFail();

        return ['ticket' => $this->detailItem($scoped), 'statusOptions' => $this->statusOptions()];
    }

    public function updateStatus(Team $team, SupportTicket $ticket, SupportTicketStatus $status): SupportTicket
    {
        $scoped = $team->supportTickets()->whereKey($ticket->getKey())->firstOrFail();
        $scoped->update(['status' => $status->value]);

        return $scoped->fresh() ?? $scoped;
    }

    /** @return array{key: string, start: CarbonImmutable|null, end: CarbonImmutable|null} */
    private function range(mixed $value): array
    {
        $key = is_string($value) && in_array($value, ['today', '7d', '30d', '90d', 'all'], true) ? $value : '30d';
        if ($key === 'all') {
            return ['key' => $key, 'start' => null, 'end' => null];
        }
        $now = CarbonImmutable::now();
        $days = ['today' => 1, '7d' => 7, '30d' => 30, '90d' => 90][$key];

        return ['key' => $key, 'start' => $now->startOfDay()->subDays($days - 1), 'end' => $now->endOfDay()];
    }

    private function status(mixed $value): string
    {
        return $value === 'all' || ! is_string($value) || SupportTicketStatus::tryFrom($value) === null ? 'all' : $value;
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array{key: string, start: CarbonImmutable|null, end: CarbonImmutable|null}  $range
     * @return Builder<SupportTicket>
     */
    private function base(Team $team, ?Bot $selectedBot, ?string $botFilter, array $range, string $search): Builder
    {
        $query = SupportTicket::query()->where('team_id', $team->id);
        if ($botFilter !== null) {
            $selectedBot instanceof Bot ? $query->where('bot_id', $selectedBot->id) : $query->whereRaw('1 = 0');
        }
        if ($range['start'] !== null && $range['end'] !== null) {
            $query->whereBetween('created_at', [$range['start'], $range['end']]);
        }
        if ($search !== '') {
            $pattern = '%'.mb_strtolower($search).'%';
            $query->where(function (Builder $query) use ($pattern): void {
                $query->whereRaw("LOWER(COALESCE(subject, '')) LIKE ?", [$pattern])->orWhereRaw("LOWER(COALESCE(provider_reference, '')) LIKE ?", [$pattern])->orWhereRaw("LOWER(COALESCE(customer_name, '')) LIKE ?", [$pattern])->orWhereRaw("LOWER(COALESCE(customer_email, '')) LIKE ?", [$pattern]);
            });
        }

        return $query;
    }

    /**
     * @param  Builder<SupportTicket>  $query
     * @return array{open: int, inProgress: int, resolved: int, closed: int, total: int}
     */
    private function summary(Builder $query): array
    {
        return ['open' => (clone $query)->where('status', SupportTicketStatus::Open->value)->count(), 'inProgress' => (clone $query)->where('status', SupportTicketStatus::InProgress->value)->count(), 'resolved' => (clone $query)->where('status', SupportTicketStatus::Resolved->value)->count(), 'closed' => (clone $query)->where('status', SupportTicketStatus::Closed->value)->count(), 'total' => (clone $query)->count()];
    }

    /** @return list<array{key: string, label: string}> */
    private function statusOptions(): array
    {
        return array_map(fn (SupportTicketStatus $status): array => ['key' => $status->value, 'label' => $this->label($status->value)], SupportTicketStatus::cases());
    }

    /** @return array<string, mixed> */
    private function listItem(SupportTicket $ticket): array
    {
        return ['reference' => $ticket->public_id, 'status' => $ticket->status->value, 'statusLabel' => $this->label($ticket->status->value), 'subject' => $ticket->subject ?? 'Support request', 'summary' => $ticket->summary, 'customerName' => $ticket->customer_name, 'customerEmail' => $ticket->customer_email, 'providerReference' => $ticket->provider_reference, 'externalUrl' => $ticket->external_url, 'createdAt' => $ticket->created_at?->toAtomString(), 'bot' => $ticket->bot === null ? null : ['id' => $ticket->bot->id, 'name' => $ticket->bot->name, 'slug' => $ticket->bot->slug], 'customer' => $ticket->customer === null ? null : ['id' => $ticket->customer->id, 'name' => $ticket->customer->name]];
    }

    /** @return array<string, mixed> */
    private function detailItem(SupportTicket $ticket): array
    {
        return [...$this->listItem($ticket), 'conversation' => $ticket->conversation === null ? null : ['reference' => $ticket->conversation->public_id], 'action' => $ticket->toolRun === null ? null : ['reference' => $ticket->toolRun->action_reference, 'completedAt' => $ticket->toolRun->completed_at?->toAtomString()]];
    }

    private function label(string $value): string
    {
        return ucfirst(str_replace('_', ' ', $value));
    }
}
