<?php

namespace App\Services\Appointments;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Bot;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class AppointmentService
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
        $base = $this->base($team, $selectedBot, $botFilter, $range);
        $query = clone $base;

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $appointments = $query
            ->select(['id', 'public_id', 'bot_id', 'conversation_id', 'customer_id', 'status', 'starts_at', 'ends_at', 'timezone', 'customer_name', 'customer_email', 'customer_phone', 'provider_reference', 'created_at'])
            ->with(['bot:id,name,slug', 'customer:id,display_name,email,phone'])
            ->orderBy('starts_at')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return [
            'filters' => ['bot' => $selectedBot?->slug, 'range' => $range['key'], 'status' => $status],
            'botOptions' => $bots->map(fn (Bot $bot): array => ['id' => $bot->id, 'name' => $bot->name, 'slug' => $bot->slug])->values()->all(),
            'statusOptions' => $this->statusOptions(),
            'summary' => $this->summary($base),
            'appointments' => $appointments->through(fn (Appointment $appointment): array => $this->listItem($appointment)),
        ];
    }

    /** @return array{appointment: array<string, mixed>, statusOptions: list<array{key: string, label: string}>} */
    public function detail(Team $team, Appointment $appointment): array
    {
        $scoped = $team->appointments()->whereKey($appointment->getKey())->with(['bot:id,name,slug', 'conversation:id,public_id', 'customer:id,display_name,email,phone', 'toolRun:id,action_reference,status,completed_at'])->firstOrFail();

        return ['appointment' => $this->detailItem($scoped), 'statusOptions' => $this->statusOptions()];
    }

    public function updateStatus(Team $team, Appointment $appointment, AppointmentStatus $status): Appointment
    {
        $scoped = $team->appointments()->whereKey($appointment->getKey())->firstOrFail();
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
        return $value === 'all' || ! is_string($value) || AppointmentStatus::tryFrom($value) === null ? 'all' : $value;
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array{key: string, start: CarbonImmutable|null, end: CarbonImmutable|null}  $range
     * @return Builder<Appointment>
     */
    private function base(Team $team, ?Bot $selectedBot, ?string $botFilter, array $range): Builder
    {
        $query = Appointment::query()->where('team_id', $team->id);

        if ($botFilter !== null) {
            $selectedBot instanceof Bot ? $query->where('bot_id', $selectedBot->id) : $query->whereRaw('1 = 0');
        }

        if ($range['start'] !== null && $range['end'] !== null) {
            $query->whereBetween('starts_at', [$range['start'], $range['end']]);
        }

        return $query;
    }

    /**
     * @param  Builder<Appointment>  $query
     * @return array{upcoming: int, today: int, completed: int, noShow: int, cancelled: int}
     */
    private function summary(Builder $query): array
    {
        $now = CarbonImmutable::now();

        return [
            'upcoming' => (clone $query)->where('status', AppointmentStatus::Scheduled->value)->where('starts_at', '>=', $now)->count(),
            'today' => (clone $query)->whereDate('starts_at', $now->toDateString())->count(),
            'completed' => (clone $query)->where('status', AppointmentStatus::Completed->value)->count(),
            'noShow' => (clone $query)->where('status', AppointmentStatus::NoShow->value)->count(),
            'cancelled' => (clone $query)->where('status', AppointmentStatus::Cancelled->value)->count(),
        ];
    }

    /** @return list<array{key: string, label: string}> */
    private function statusOptions(): array
    {
        return array_map(fn (AppointmentStatus $status): array => ['key' => $status->value, 'label' => $this->label($status->value)], AppointmentStatus::cases());
    }

    /** @return array<string, mixed> */
    private function listItem(Appointment $appointment): array
    {
        return [
            'reference' => $appointment->public_id,
            'status' => $appointment->status->value,
            'statusLabel' => $this->label($appointment->status->value),
            'startsAt' => $appointment->starts_at?->toAtomString(),
            'endsAt' => $appointment->ends_at?->toAtomString(),
            'timezone' => $appointment->timezone,
            'customerName' => $appointment->customer_name,
            'customerEmail' => $appointment->customer_email,
            'customerPhone' => $appointment->customer_phone,
            'providerReference' => $appointment->provider_reference,
            'createdAt' => $appointment->created_at?->toAtomString(),
            'bot' => $appointment->bot === null ? null : ['id' => $appointment->bot->id, 'name' => $appointment->bot->name, 'slug' => $appointment->bot->slug],
            'customer' => $appointment->customer === null ? null : ['id' => $appointment->customer->id, 'name' => $appointment->customer->name],
        ];
    }

    /** @return array<string, mixed> */
    private function detailItem(Appointment $appointment): array
    {
        return [...$this->listItem($appointment), 'conversation' => $appointment->conversation === null ? null : ['reference' => $appointment->conversation->public_id], 'action' => $appointment->toolRun === null ? null : ['reference' => $appointment->toolRun->action_reference, 'completedAt' => $appointment->toolRun->completed_at?->toAtomString()]];
    }

    private function label(string $value): string
    {
        return ucfirst(str_replace('_', ' ', $value));
    }
}
